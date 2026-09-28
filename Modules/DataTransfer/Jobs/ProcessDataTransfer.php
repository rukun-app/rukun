<?php

namespace Modules\DataTransfer\Jobs;

use Core\Audit\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\DataTransfer\DataTransferRegistry;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Files\Services\FileStorageService;
use Modules\Realtime\Contracts\RealtimePublisher;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

class ProcessDataTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $transferId) {}

    public function handle(DataTransferRegistry $registry, FileStorageService $files, RealtimePublisher $realtime): void
    {
        $transfer = DataTransfer::query()->where('public_id', $this->transferId)->with(['user', 'inputFile'])->firstOrFail();
        if ($transfer->status !== TransferStatus::Pending) {
            return;
        }

        $transfer->update(['status' => TransferStatus::Processing, 'started_at' => now(), 'failure_message' => null]);
        $this->publish($transfer->refresh(), $realtime);

        try {
            $handler = $registry->get($transfer->type);
            if ($transfer->direction === 'export') {
                $this->export($transfer, $handler, $files, $realtime);
            } else {
                $this->import($transfer, $handler, $realtime);
            }
        } catch (Throwable $exception) {
            $transfer->refresh();
            if ($transfer->status !== TransferStatus::Cancelled) {
                $transfer->update([
                    'status' => TransferStatus::Failed,
                    'failure_message' => __('data-transfer::messages.failed'),
                    'finished_at' => now(),
                ]);
                Audit::record('data_transfer.failed', $transfer, [
                    'direction' => $transfer->direction,
                    'type' => $transfer->type,
                ]);
                $this->publish($transfer->refresh(), $realtime);
            }
            Log::error('Data transfer failed.', ['transfer_id' => $transfer->public_id, 'type' => $transfer->type, 'exception' => $exception]);
        }
    }

    private function export(DataTransfer $transfer, object $handler, FileStorageService $files, RealtimePublisher $realtime): void
    {
        $format = ($transfer->options['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $path = $this->temporaryPath('core-export-', $format);
        $stream = null;
        $writer = null;

        try {
            if ($format === 'xlsx') {
                $writer = new XlsxWriter;
                $writer->openToFile($path);
                $writer->addRow(Row::fromValues($handler->columns()));
            } else {
                $stream = fopen($path, 'wb');
                throw_if($stream === false, new \RuntimeException('Unable to open export file.'));
                fputcsv($stream, $handler->columns(), escape: '');
            }
            $processed = 0;
            foreach ($handler->exportRows($transfer->user, $transfer->options ?? []) as $row) {
                if ($this->cancelled($transfer)) {
                    return;
                }
                $values = array_map($this->safeCell(...), array_values($row));
                if ($writer) {
                    $writer->addRow(Row::fromValues($values));
                } else {
                    fputcsv($stream, $values, escape: '');
                }
                $processed++;
                if ($processed % 100 === 0) {
                    $transfer->update(['processed_rows' => $processed, 'successful_rows' => $processed]);
                }
            }
            $writer?->close();
            $writer = null;
            if (is_resource($stream)) {
                fclose($stream);
                $stream = null;
            }

            $mimeType = $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv';
            $upload = new UploadedFile($path, $transfer->type.'-'.now()->format('Ymd-His').'.'.$format, $mimeType, null, true);
            $output = $files->store($upload, $transfer->user, $upload->getClientOriginalName(), ['data_transfer_id' => $transfer->public_id]);
            $transfer->update([
                'status' => TransferStatus::Completed,
                'output_file_id' => $output->getKey(),
                'total_rows' => $processed,
                'processed_rows' => $processed,
                'successful_rows' => $processed,
                'finished_at' => now(),
            ]);
            Audit::record('data_transfer.completed', $transfer, [
                'direction' => $transfer->direction,
                'type' => $transfer->type,
                'successful_rows' => $processed,
                'failed_rows' => 0,
            ]);
            $this->publish($transfer->refresh(), $realtime);
        } finally {
            $writer?->close();
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function import(DataTransfer $transfer, object $handler, RealtimePublisher $realtime): void
    {
        throw_unless($transfer->inputFile, new \RuntimeException('Input file is unavailable.'));
        if ($this->isXlsx($transfer)) {
            $this->importXlsx($transfer, $handler, $realtime);

            return;
        }
        $stream = Storage::disk($transfer->inputFile->disk)->readStream($transfer->inputFile->path);
        throw_if($stream === false, new \RuntimeException('Input file is unavailable.'));
        $headers = fgetcsv($stream, escape: '');
        throw_if($headers === false, ValidationException::withMessages(['file' => ['The CSV file is empty.']]));
        $headers = array_map(fn (string $value): string => trim($value, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
        $missing = array_diff($handler->importColumns(), $headers);
        throw_if($missing !== [], ValidationException::withMessages(['file' => ['Missing CSV columns: '.implode(', ', $missing).'.']]));

        $processed = $success = $failed = 0;
        $errors = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($this->cancelled($transfer)) {
                fclose($stream);

                return;
            }
            $processed++;
            $values = array_pad($values, count($headers), null);
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            try {
                $handler->importRow($transfer->user, $row, $transfer->options ?? []);
                $success++;
            } catch (ValidationException $exception) {
                $failed++;
                if (count($errors) < 100) {
                    $errors[] = ['row' => $processed + 1, 'errors' => $exception->errors()];
                }
            } catch (Throwable) {
                $failed++;
                if (count($errors) < 100) {
                    $errors[] = ['row' => $processed + 1, 'errors' => ['row' => [__('data-transfer::messages.row_failed')]]];
                }
            }
            if ($processed % 25 === 0) {
                $transfer->update(['processed_rows' => $processed, 'successful_rows' => $success, 'failed_rows' => $failed, 'error_summary' => $errors ?: null]);
            }
        }
        fclose($stream);

        $transfer->update([
            'status' => TransferStatus::Completed,
            'total_rows' => $processed,
            'processed_rows' => $processed,
            'successful_rows' => $success,
            'failed_rows' => $failed,
            'error_summary' => $errors ?: null,
            'finished_at' => now(),
        ]);
        Audit::record('data_transfer.completed', $transfer, [
            'direction' => $transfer->direction,
            'type' => $transfer->type,
            'successful_rows' => $success,
            'failed_rows' => $failed,
        ]);
        $this->publish($transfer->refresh(), $realtime);
    }

    private function importXlsx(DataTransfer $transfer, object $handler, RealtimePublisher $realtime): void
    {
        $path = $this->temporaryPath('core-import-', 'xlsx');
        $source = Storage::disk($transfer->inputFile->disk)->readStream($transfer->inputFile->path);
        throw_if($source === false, new \RuntimeException('Input file is unavailable.'));
        $target = fopen($path, 'wb');
        throw_if($target === false, new \RuntimeException('Unable to allocate import file.'));
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $reader = new XlsxReader;
        try {
            $reader->open($path);
            $sheet = $reader->getSheetIterator()->current();
            throw_unless($sheet, ValidationException::withMessages(['file' => ['The XLSX workbook has no worksheet.']]));
            $rows = $sheet->getRowIterator();
            $rows->rewind();
            throw_unless($rows->valid(), ValidationException::withMessages(['file' => ['The XLSX file is empty.']]));
            $headers = array_map(fn (mixed $value): string => trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"), $rows->current()->toArray());
            $missing = array_diff($handler->importColumns(), $headers);
            throw_if($missing !== [], ValidationException::withMessages(['file' => ['Missing XLSX columns: '.implode(', ', $missing).'.']]));
            $rows->next();

            $processed = $success = $failed = 0;
            $errors = [];
            while ($rows->valid()) {
                if ($this->cancelled($transfer)) {
                    return;
                }
                $values = array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $rows->current()->toArray());
                $values = array_pad($values, count($headers), null);
                $row = array_combine($headers, array_slice($values, 0, count($headers)));
                $processed++;
                try {
                    $handler->importRow($transfer->user, $row, $transfer->options ?? []);
                    $success++;
                } catch (ValidationException $exception) {
                    $failed++;
                    if (count($errors) < 100) {
                        $errors[] = ['row' => $processed + 1, 'errors' => $exception->errors()];
                    }
                } catch (Throwable) {
                    $failed++;
                    if (count($errors) < 100) {
                        $errors[] = ['row' => $processed + 1, 'errors' => ['row' => [__('data-transfer::messages.row_failed')]]];
                    }
                }
                if ($processed % 25 === 0) {
                    $transfer->update(['processed_rows' => $processed, 'successful_rows' => $success, 'failed_rows' => $failed, 'error_summary' => $errors ?: null]);
                }
                $rows->next();
            }

            $transfer->update([
                'status' => TransferStatus::Completed,
                'total_rows' => $processed,
                'processed_rows' => $processed,
                'successful_rows' => $success,
                'failed_rows' => $failed,
                'error_summary' => $errors ?: null,
                'finished_at' => now(),
            ]);
            Audit::record('data_transfer.completed', $transfer, ['direction' => $transfer->direction, 'type' => $transfer->type, 'successful_rows' => $success, 'failed_rows' => $failed]);
            $this->publish($transfer->refresh(), $realtime);
        } finally {
            $reader->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function isXlsx(DataTransfer $transfer): bool
    {
        return $transfer->inputFile->extension === 'xlsx'
            || $transfer->inputFile->mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    private function temporaryPath(string $prefix, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        throw_if($path === false, new \RuntimeException('Unable to allocate transfer file.'));
        $target = $path.'.'.$extension;
        throw_unless(rename($path, $target), new \RuntimeException('Unable to prepare transfer file.'));

        return $target;
    }

    private function cancelled(DataTransfer $transfer): bool
    {
        return $transfer->refresh()->status === TransferStatus::Cancelled;
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function publish(DataTransfer $transfer, RealtimePublisher $realtime): void
    {
        $realtime->publish($transfer->user, 'data_transfer.updated', [
            'transfer_id' => $transfer->public_id,
            'direction' => $transfer->direction,
            'status' => $transfer->status->value,
            'processed_rows' => $transfer->processed_rows,
            'total_rows' => $transfer->total_rows,
        ], $transfer);
    }
}
