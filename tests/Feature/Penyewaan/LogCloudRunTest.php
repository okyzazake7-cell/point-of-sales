<?php

namespace Tests\Feature\Penyewaan;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\GoogleCloudLoggingFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * Log di Cloud Run (dokumen 25 di repo Aishii): Dockerfile mengisi
 * LOG_STDERR_FORMATTER dengan formatter JSON Google. Baris polos masuk Cloud
 * Logging tanpa tingkat; satu baris JSON dibaca sebagai satu entri berikut
 * `severity`-nya — dan jejak tumpukannya harus ikut, padahal formatter JSON
 * bawaannya membuangnya.
 */
class LogCloudRunTest extends TestCase
{
    public function test_satu_catatan_satu_baris_json_berikut_severity_dan_jejak_tumpukan(): void
    {
        config(['logging.channels.stderr.formatter' => GoogleCloudLoggingFormatter::class]);

        $formatter = Log::channel('stderr')->getLogger()->getHandlers()[0]->getFormatter();
        $baris = $formatter->format(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'production',
            level: Level::Error,
            message: 'Gagal menyimpan transaksi.',
            context: ['exception' => new RuntimeException('uji')],
        ));

        $this->assertStringNotContainsString("\n", rtrim($baris));

        $catatan = json_decode($baris, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ERROR', $catatan['severity']);
        $this->assertSame('Gagal menyimpan transaksi.', $catatan['message']);
        $this->assertNotEmpty($catatan['context']['exception']['trace'] ?? []);
    }
}
