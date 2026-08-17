<?php

namespace Tests\Feature;

use App\Support\UploadGate;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * The one gate every upload passes.
 *
 * Every file here is a REAL temp file, never UploadedFile::fake() — the fake
 * reports the mime type its NAME suggests, which is exactly the lie the gate
 * exists to catch, so the fake cannot exercise any of these checks.
 */
class UploadGateTest extends TestCase
{
    /** Real temp files made during a test, deleted after it. */
    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /** A real file on disk wearing the given name, with the given bytes inside. */
    protected function realFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gate');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Bytes that finfo genuinely recognises as a PDF. */
    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    protected function gate(): UploadGate
    {
        return new UploadGate;
    }

    // ── Extension and sniffed-mime discipline ────────────────────────────

    public function test_a_php_script_named_cv_pdf_is_refused(): void
    {
        try {
            $this->gate()->accept($this->realFile('cv.pdf', '<?php system($_GET["c"]);'), 'cv');
            $this->fail('A PHP script wearing a .pdf name was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contents do not match', $e->getMessage());
        }
    }

    public function test_a_mime_and_extension_disagreement_is_refused_for_documents(): void
    {
        // A real PNG header inside a file called invoice.pdf.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        try {
            $this->gate()->accept($this->realFile('invoice.pdf', $png), 'document');
            $this->fail('A PNG wearing a .pdf name was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contents do not match', $e->getMessage());
        }
    }

    public function test_an_extension_off_the_allow_list_is_refused(): void
    {
        try {
            $this->gate()->accept($this->realFile('tool.exe', 'MZ'.str_repeat("\0", 64)), 'document');
            $this->fail('An .exe was accepted as a document.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('.exe', $e->getMessage());
        }
    }

    public function test_the_image_purpose_refuses_a_pdf(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gate()->accept($this->realFile('logo.pdf', $this->pdfBytes()), 'image');
    }

    public function test_the_import_purpose_accepts_a_real_csv(): void
    {
        $this->gate()->accept($this->realFile('customers.csv', "name,email\nJean,jean@example.test\n"), 'import');

        $this->addToAssertionCount(1); // no exception is the assertion
    }

    public function test_an_unknown_purpose_is_a_programmer_error(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gate()->accept($this->realFile('a.pdf', $this->pdfBytes()), 'no-such-purpose');
    }

    // ── Size caps ────────────────────────────────────────────────────────

    public function test_an_oversize_file_is_refused_with_a_readable_reason(): void
    {
        $big = $this->pdfBytes().str_repeat('A', UploadGate::PURPOSES['cv']['max_bytes']);

        try {
            $this->gate()->accept($this->realFile('cv.pdf', $big), 'cv');
            $this->fail('An oversize CV was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('smaller than', $e->getMessage());
            $this->assertStringContainsString('MB', $e->getMessage());
        }
    }

    // ── The scanner ──────────────────────────────────────────────────────

    public function test_clean_files_pass_when_no_scanner_is_configured(): void
    {
        // No CLAMAV_SOCKET in the test environment: scan() is a documented no-op.
        $gate = $this->gate();
        $this->assertNull($gate->socket());

        $gate->accept($this->realFile('terms.pdf', $this->pdfBytes()), 'document');

        $this->addToAssertionCount(1);
    }

    /**
     * The socket comes from config, not a raw env() call. env() outside
     * config/ reads null once `config:cache` has run — which is every
     * production deploy — so a raw call would have disabled scanning exactly
     * where it was configured to run.
     */
    public function test_the_socket_is_read_from_config_so_it_survives_config_cache(): void
    {
        config(['services.clamav.socket' => 'tcp://127.0.0.1:3310']);

        $this->assertSame('tcp://127.0.0.1:3310', $this->gate()->socket());
    }

    public function test_an_eicar_hit_is_refused_without_naming_the_scanner(): void
    {
        // The daemon's answer is faked at the socket layer; clamd need not exist.
        $gate = new class extends UploadGate
        {
            public function socket(): ?string
            {
                return 'tcp://127.0.0.1:3310';
            }

            protected function connect(string $socket)
            {
                return fopen('php://memory', 'r+b');
            }

            protected function readVerdict($stream): string
            {
                return 'stream: Eicar-Signature FOUND';
            }
        };

        /*
         * The EICAR test string, padded past the 128-byte window in which the
         * EICAR convention says AV engines should trigger — otherwise the host
         * machine's own antivirus (Windows Defender, notably) quarantines the
         * temp file mid-test. The daemon's FOUND answer is faked anyway; what
         * is under test is the gate's reaction, not clamd's detection.
         */
        $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
            .str_repeat(' ', 200);

        try {
            // A txt is on the document allow-list, so only the scanner refuses it.
            $gate->accept($this->realFile('notes.txt', $eicar), 'document');
            $this->fail('An EICAR file was accepted while the daemon answered FOUND.');
        } catch (RuntimeException $e) {
            // Polite and quiet: the public-facing reason names no scanner.
            $this->assertStringNotContainsString('virus', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('clam', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('eicar', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('scan', strtolower($e->getMessage()));
        }
    }

    public function test_a_clean_verdict_from_the_daemon_passes(): void
    {
        $gate = new class extends UploadGate
        {
            public function socket(): ?string
            {
                return 'tcp://127.0.0.1:3310';
            }

            protected function connect(string $socket)
            {
                return fopen('php://memory', 'r+b');
            }

            protected function readVerdict($stream): string
            {
                return 'stream: OK';
            }
        };

        $gate->accept($this->realFile('terms.pdf', $this->pdfBytes()), 'document');

        $this->addToAssertionCount(1);
    }

    public function test_an_unreachable_daemon_degrades_to_sniff_only(): void
    {
        // Configured but down (shared hosting, daemon restarting): the
        // sniff checks still ran, and the upload is not held hostage.
        $gate = new class extends UploadGate
        {
            public function socket(): ?string
            {
                return 'unix:///nowhere/clamd.ctl';
            }

            protected function connect(string $socket)
            {
                return false;
            }
        };

        $gate->accept($this->realFile('terms.pdf', $this->pdfBytes()), 'document');

        $this->addToAssertionCount(1);
    }

    public function test_the_instream_framing_is_what_clamd_expects(): void
    {
        $gate = new class extends UploadGate
        {
            public string $sent = '';

            public function socket(): ?string
            {
                return 'tcp://127.0.0.1:3310';
            }

            protected function connect(string $socket)
            {
                return fopen('php://memory', 'r+b');
            }

            protected function readVerdict($stream): string
            {
                // The gate closes the stream after reading; copy what was
                // written to it before that happens.
                rewind($stream);
                $this->sent = (string) stream_get_contents($stream);

                return 'stream: OK';
            }
        };

        $gate->accept($this->realFile('note.txt', 'hello'), 'document');

        $sent = $gate->sent;

        // zINSTREAM\0, then <4-byte big-endian length><chunk>, then a zero-length chunk.
        $this->assertStringStartsWith("zINSTREAM\0", $sent);
        $this->assertStringContainsString(pack('N', 5).'hello', $sent);
        $this->assertStringEndsWith(pack('N', 0), $sent);
    }
}
