<?php

$before = file_get_contents('/tmp/before-scores.txt');
$after = file_get_contents('/tmp/after-scores.txt');

$beforeLines = explode("\n", $before);
$afterLines = explode("\n", $after);

echo "SCORE COMPARISON - BEFORE vs AFTER\n";
echo str_repeat("=", 120) . "\n";
printf("%-25s | %5s | %4s | %10s | %10s | %10s | Status\n",
    "Player", "Pick", "Tier", "Before", "After", "Change", "");
echo str_repeat("-", 120) . "\n";

for ($i = 2; $i < count($beforeLines); $i++) {
    if (empty(trim($beforeLines[$i]))) continue;

    preg_match('/\s+(\S+.*?)\s+\|\s+Pick\s+(\S+)\s+\|\s+Tier\s+(\S+)\s+\|\s+Score:\s+([\d.]+)/', $beforeLines[$i], $beforeMatches);
    preg_match('/\s+(\S+.*?)\s+\|\s+Pick\s+(\S+)\s+\|\s+Tier\s+(\S+)\s+\|\s+Score:\s+([\d.]+)/', $afterLines[$i], $afterMatches);

    if (!empty($beforeMatches) && !empty($afterMatches)) {
        $name = trim($beforeMatches[1]);
        $pick = $beforeMatches[2];
        $tier = $beforeMatches[3];
        $scoreBefore = (float)$beforeMatches[4];
        $scoreAfter = (float)$afterMatches[4];
        $change = $scoreAfter - $scoreBefore;

        $changeStr = sprintf("%+.4f", $change);
        $changePercent = $scoreBefore > 0 ? sprintf("(%+.1f%%)", ($change / $scoreBefore) * 100) : "";

        // Highlight significant changes
        $highlight = abs($change) > 0.05 ? "***" : "";

        // Check if status changed
        $statusChange = "";
        if (strpos($beforeLines[$i], 'Expected:   NOT | Actual:   NOT') !== false &&
            strpos($afterLines[$i], 'Expected:   NOT | Actual: STEAL') !== false) {
            $statusChange = "FLIPPED TO STEAL!";
            $highlight = "!!!";
        }

        printf("%s %-22s | %5s | %4s | %10.4f | %10.4f | %10s | %s\n",
            $highlight,
            substr($name, 0, 22),
            $pick,
            $tier,
            $scoreBefore,
            $scoreAfter,
            $changeStr . " " . $changePercent,
            $statusChange
        );
    }
}

echo "\n" . str_repeat("=", 120) . "\n";
echo "Legend: *** = significant change (>0.05), !!! = status flip\n";
