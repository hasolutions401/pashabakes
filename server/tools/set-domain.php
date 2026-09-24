<?php
/*
 * Switches the website's public address (canonical links, social previews, sitemap, robots.txt).
 * Run from the project folder:   php server/tools/set-domain.php https://pashabakess.com
 * Then commit and push. Also set 'site_url' in server/config.php on the server (used in emails).
 */
declare(strict_types=1);

$new = rtrim((string) ($argv[1] ?? ''), '/');
if (!preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}$#i', $new)) {
    fwrite(STDERR, "Usage: php server/tools/set-domain.php https://your-domain.com\n");
    exit(1);
}
$dist = dirname(__DIR__, 2) . '/dist';
if (!preg_match('#<loc>(https://[^/<]+)/#', (string) file_get_contents("$dist/sitemap.xml"), $m)) {
    fwrite(STDERR, "Could not find the current address in dist/sitemap.xml\n");
    exit(1);
}
$old = $m[1];
if ($old === $new) {
    echo "Already using $new\n";
    exit(0);
}
$changed = 0;
foreach ([...glob("$dist/*.html"), "$dist/sitemap.xml", "$dist/robots.txt"] as $file) {
    $text = (string) file_get_contents($file);
    $updated = str_replace($old . '/', $new . '/', $text);
    if ($updated !== $text) {
        file_put_contents($file, $updated);
        $changed++;
        echo '  updated ' . basename($file) . "\n";
    }
}
echo "Switched $old → $new in $changed files.\n";
