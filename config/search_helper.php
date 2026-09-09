<?php
declare(strict_types=1);

/**
 * Smart Search Helper for SQL Queries:
 * Features:
 * 1. Multi-Word Token Matching (Splits words, out-of-order matching)
 * 2. Whitespace Insensitive (Collapses multi-spaces in database and query)
 * 3. Thai-English Synonym Expansion (3D <-> สามมิติ, Animation <-> แอนิเมชัน, etc.)
 */
function build_smart_search_query(string $searchQuery, array &$params): string
{
    $cleanQuery = trim((string) preg_replace('/\s+/', ' ', $searchQuery));
    if ($cleanQuery === '') {
        return '';
    }

    $synonyms = [
        '3d' => ['3d', '3มิติ', 'สามมิติ', '3 มิติ'],
        '3มิติ' => ['3d', '3มิติ', 'สามมิติ', '3 มิติ'],
        'สามมิติ' => ['3d', '3มิติ', 'สามมิติ', '3 มิติ'],
        'animation' => ['animation', 'แอนิเมชัน', 'แอนิเมชั่น', 'ภาพเคลื่อนไหว'],
        'แอนิเมชัน' => ['animation', 'แอนิเมชัน', 'แอนิเมชั่น', 'ภาพเคลื่อนไหว'],
        'แอนิเมชั่น' => ['animation', 'แอนิเมชัน', 'แอนิเมชั่น', 'ภาพเคลื่อนไหว'],
        'short film' => ['short film', 'ภาพยนตร์สั้น', 'หนังสั้น'],
        'ภาพยนตร์สั้น' => ['short film', 'ภาพยนตร์สั้น', 'หนังสั้น'],
        'หนังสั้น' => ['short film', 'ภาพยนตร์สั้น', 'หนังสั้น'],
        'application' => ['application', 'app', 'แอปพลิเคชัน', 'แอปพลิเคชั่น', 'แอพพลิเคชั่น', 'ระบบ'],
        'app' => ['application', 'app', 'แอปพลิเคชัน', 'แอปพลิเคชั่น', 'แอพพลิเคชั่น', 'ระบบ'],
        'แอปพลิเคชัน' => ['application', 'app', 'แอปพลิเคชัน', 'แอปพลิเคชั่น', 'แอพพลิเคชั่น', 'ระบบ'],
        'game' => ['game', 'เกม', 'เกมส์', 'เกมคอมพิวเตอร์'],
        'เกม' => ['game', 'เกม', 'เกมส์', 'เกมคอมพิวเตอร์'],
        'ux' => ['ux', 'ui', 'ux/ui', 'ยูเอ็กซ์', 'ออกแบบเว็บไซต์'],
        'ui' => ['ux', 'ui', 'ux/ui', 'ยูไอ', 'ออกแบบเว็บไซต์'],
        'drone' => ['drone', 'โดรน', 'ไร้คนขับ'],
        'โดรน' => ['drone', 'โดรน', 'ไร้คนขับ'],
        'media' => ['media', 'สื่อ', 'สื่อโฆษณา'],
        'สื่อ' => ['media', 'สื่อ', 'สื่อโฆษณา'],
    ];

    $tokens = explode(' ', $cleanQuery);
    $tokenSqlParts = [];
    $tokenIdx = 0;

    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }

        $tokenIdx++;
        $tokenLower = mb_strtolower($token, 'UTF-8');
        $variants = [$token];

        if (isset($synonyms[$tokenLower])) {
            foreach ($synonyms[$tokenLower] as $syn) {
                if (!in_array($syn, $variants, true)) {
                    $variants[] = $syn;
                }
            }
        }

        $variantSqlParts = [];
        $varIdx = 0;
        foreach ($variants as $variant) {
            $varIdx++;
            $p1 = ":smart_t{$tokenIdx}_v{$varIdx}_f1";
            $p2 = ":smart_t{$tokenIdx}_v{$varIdx}_f2";
            $p3 = ":smart_t{$tokenIdx}_v{$varIdx}_f3";
            $p4 = ":smart_t{$tokenIdx}_v{$varIdx}_f4";
            $p5 = ":smart_t{$tokenIdx}_v{$varIdx}_f5";
            $val = '%' . $variant . '%';

            $params[$p1] = $val;
            $params[$p2] = $val;
            $params[$p3] = $val;
            $params[$p4] = $val;
            $params[$p5] = $val;

            $variantSqlParts[] = "(REPLACE(p.title_th, '  ', ' ') LIKE {$p1} OR REPLACE(p.title_en, '  ', ' ') LIKE {$p2} OR REPLACE(p.creators, '  ', ' ') LIKE {$p3} OR REPLACE(c.name, '  ', ' ') LIKE {$p4} OR REPLACE(adv.full_name, '  ', ' ') LIKE {$p5})";
        }

        $tokenSqlParts[] = '(' . implode(' OR ', $variantSqlParts) . ')';
    }

    if ($tokenSqlParts === []) {
        return '';
    }

    return ' AND (' . implode(' AND ', $tokenSqlParts) . ')';
}
