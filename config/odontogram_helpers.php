<?php

if (!function_exists('odontogram_normalize_data')) {
    /**
     * Normalize odontogram JSON to FDI keys with per-surface legend codes.
     */
    function odontogram_normalize_data($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        if (isset($raw['teeth']) && is_array($raw['teeth'])) {
            $raw = $raw['teeth'];
        }

        $allFdi = [
            '18','17','16','15','14','13','12','11',
            '21','22','23','24','25','26','27','28',
            '31','32','33','34','35','36','37','38',
            '41','42','43','44','45','46','47','48',
        ];

        $universalToFdi = [
            1 => '18', 2 => '17', 3 => '16', 4 => '15', 5 => '14', 6 => '13', 7 => '12', 8 => '11',
            9 => '21', 10 => '22', 11 => '23', 12 => '24', 13 => '25', 14 => '26', 15 => '27', 16 => '28',
            17 => '31', 18 => '32', 19 => '33', 20 => '34', 21 => '35', 22 => '36', 23 => '37', 24 => '38',
            25 => '41', 26 => '42', 27 => '43', 28 => '44', 29 => '45', 30 => '46', 31 => '47', 32 => '48',
        ];

        $legacyMap = [
            'missing' => 'M',
            'rootMissing' => 'RF',
            'caries' => 'C',
            'filled' => 'Am',
            'crown' => 'GC',
            'implant' => 'GI',
        ];

        $validSurfaces = ['O', 'M', 'D', 'B', 'L', 'root', 'whole'];
        $out = [];

        foreach ($raw as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fdi = (string)$key;
            if (!in_array($fdi, $allFdi, true)) {
                if (ctype_digit($fdi) && (int)$fdi <= 32 && isset($universalToFdi[(int)$fdi])) {
                    $fdi = $universalToFdi[(int)$fdi];
                }
            }
            if (!in_array($fdi, $allFdi, true)) {
                continue;
            }

            $comment = trim((string)($entry['comment'] ?? ''));
            $surfaces = [];

            if (!empty($entry['surfaces']) && is_array($entry['surfaces'])) {
                foreach ($entry['surfaces'] as $surface => $labels) {
                    if (!in_array($surface, $validSurfaces, true)) {
                        continue;
                    }
                    $list = is_array($labels) ? $labels : [$labels];
                    $clean = array_values(array_unique(array_filter(array_map('strval', $list))));
                    if ($clean) {
                        $surfaces[$surface] = $clean;
                    }
                }
            } elseif (!empty($entry['statuses']) && is_array($entry['statuses'])) {
                foreach ($entry['statuses'] as $st) {
                    $st = (string)$st;
                    if (!isset($legacyMap[$st])) {
                        continue;
                    }
                    $surface = $st === 'rootMissing' ? 'root' : ($st === 'missing' ? 'whole' : 'O');
                    $surfaces[$surface] = [$legacyMap[$st]];
                }
            }

            if ($comment !== '' || $surfaces) {
                $out[$fdi] = [
                    'comment' => $comment,
                    'surfaces' => $surfaces,
                ];
            }
        }

        return $out;
    }
}

if (!function_exists('odontogram_format_findings')) {
    function odontogram_format_findings($raw): array
    {
        $normalized = odontogram_normalize_data($raw);
        $findings = [];

        foreach ($normalized as $fdi => $entry) {
            $parts = [];
            foreach ($entry['surfaces'] ?? [] as $surface => $labels) {
                if (!$labels) {
                    continue;
                }
                $parts[] = $surface . ': ' . implode('/', (array)$labels);
            }
            if (!empty($entry['comment'])) {
                $parts[] = trim((string)$entry['comment']);
            }
            $remark = trim(implode(' — ', $parts));
            if ($remark !== '') {
                $findings[] = [
                    'tooth_no' => (string)$fdi,
                    'remark' => $remark,
                ];
            }
        }

        return $findings;
    }
}

if (!function_exists('odontogram_embed_src')) {
    /** Single iframe entry point for 2D chart + realistic 3D preview (odontogram/index.html). */
    function odontogram_embed_src(): string
    {
        return '../odontogram/index.html';
    }
}

if (!function_exists('odontogram_embed_height_px')) {
    function odontogram_embed_height_px(): int
    {
        return 820;
    }
}

if (!function_exists('odontogram_render_rotate_prompt')) {
    /**
     * Overlay shown inside a .odontogram-viewport while it's fullscreen (native
     * or the iOS pseudo-fullscreen fallback — see assets/js/main.js) on a
     * touch device held in portrait. Visibility is pure CSS (see
     * assets/css/odontogram-rotate-prompt.css); this just emits the markup.
     */
    function odontogram_render_rotate_prompt(): void
    {
        ?>
        <div class="odontogram-rotate-prompt" role="status">
            <div class="odontogram-rotate-prompt__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="2" width="12" height="20" rx="2.5"></rect>
                    <line x1="11" y1="18.4" x2="13" y2="18.4"></line>
                </svg>
            </div>
            <p class="odontogram-rotate-prompt__text">Rotate your device for a better view of the chart</p>
            <button type="button" class="odontogram-rotate-prompt__dismiss" data-odontogram-rotate-dismiss>Continue in portrait</button>
        </div>
        <?php
    }
}
