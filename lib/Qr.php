<?php
declare(strict_types=1);

/** QR codes as inline SVG via the `qrencode` binary (no root needed). */
final class Qr
{
    public static function svg(string $text): string
    {
        $p = @proc_open(['qrencode', '-t', 'SVG', '-l', 'L', '-m', '2', '-s', '6', '--svg-path', '-o', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new RuntimeException('qrencode is not installed.');
        }
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $svg = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0 || strpos($svg, '<svg') === false) {
            // Older qrencode builds lack --svg-path; retry without it.
            if (strpos($err, 'svg-path') !== false) {
                return self::svgLegacy($text);
            }
            throw new RuntimeException('qrencode failed: ' . trim($err));
        }
        return self::clean($svg);
    }

    private static function svgLegacy(string $text): string
    {
        $p = proc_open(['qrencode', '-t', 'SVG', '-l', 'L', '-m', '2', '-s', '6', '-o', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $svg = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return self::clean($svg);
    }

    /** Drop the XML prolog/comments so the SVG can be embedded inline. */
    private static function clean(string $svg): string
    {
        $svg = preg_replace('/<\?xml.*?\?>/s', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE.*?>/s', '', $svg) ?? $svg;
        $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
        return trim(preg_replace('/<svg /', '<svg role="img" aria-label="QR code" class="qr" ', $svg, 1) ?? $svg);
    }
}
