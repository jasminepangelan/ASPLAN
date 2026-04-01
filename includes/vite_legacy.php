<?php

if (!function_exists('legacyViteReadManifest')) {
    function legacyViteReadManifest()
    {
        static $manifest = null;
        static $loaded = false;

        if ($loaded) {
            return $manifest;
        }

        $loaded = true;
        $manifestPath = __DIR__ . '/../laravel-app/public/build/manifest.json';
        if (!is_file($manifestPath)) {
            $manifest = [];
            return $manifest;
        }

        $contents = file_get_contents($manifestPath);
        $decoded = json_decode((string)$contents, true);
        $manifest = is_array($decoded) ? $decoded : [];

        return $manifest;
    }
}

if (!function_exists('legacyViteResolveEntryAssets')) {
    function legacyViteResolveEntryAssets($entry)
    {
        $manifest = legacyViteReadManifest();
        if (!isset($manifest[$entry]) || !is_array($manifest[$entry])) {
            return ['scripts' => [], 'styles' => []];
        }

        $scripts = [];
        $styles = [];
        $visited = [];

        $collect = function ($key) use (&$collect, &$manifest, &$scripts, &$styles, &$visited) {
            if (isset($visited[$key]) || !isset($manifest[$key]) || !is_array($manifest[$key])) {
                return;
            }

            $visited[$key] = true;
            $item = $manifest[$key];

            if (!empty($item['file']) && is_string($item['file'])) {
                $scripts[] = $item['file'];
            }

            if (!empty($item['css']) && is_array($item['css'])) {
                foreach ($item['css'] as $cssFile) {
                    if (is_string($cssFile)) {
                        $styles[] = $cssFile;
                    }
                }
            }

            if (!empty($item['imports']) && is_array($item['imports'])) {
                foreach ($item['imports'] as $importKey) {
                    if (is_string($importKey)) {
                        $collect($importKey);
                    }
                }
            }
        };

        $collect($entry);

        return [
            'scripts' => array_values(array_unique($scripts)),
            'styles' => array_values(array_unique($styles)),
        ];
    }
}

if (!function_exists('renderLegacyViteTags')) {
    function renderLegacyViteTags(array $entries, $assetBasePath = '../laravel-app/public/build/')
    {
        $output = [];
        $printedScripts = [];
        $printedStyles = [];
        $base = rtrim((string)$assetBasePath, '/') . '/';

        foreach ($entries as $entry) {
            $assets = legacyViteResolveEntryAssets((string)$entry);

            foreach ($assets['styles'] as $styleFile) {
                if (isset($printedStyles[$styleFile])) {
                    continue;
                }

                $printedStyles[$styleFile] = true;
                $href = htmlspecialchars($base . ltrim($styleFile, '/'), ENT_QUOTES, 'UTF-8');
                $output[] = '<link rel="stylesheet" href="' . $href . '">';
            }

            foreach ($assets['scripts'] as $scriptFile) {
                if (isset($printedScripts[$scriptFile])) {
                    continue;
                }

                $printedScripts[$scriptFile] = true;
                $src = htmlspecialchars($base . ltrim($scriptFile, '/'), ENT_QUOTES, 'UTF-8');
                $output[] = '<script type="module" src="' . $src . '"></script>';
            }
        }

        return implode("\n", $output);
    }
}
