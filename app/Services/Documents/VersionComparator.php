<?php

namespace App\Services\Documents;

use App\Models\BusinessDocumentVersion;

/**
 * What changed between two versions of a document.
 *
 * Word-level, not line-level: a contract is prose, and telling somebody
 * "paragraph 3 differs" is far less useful than showing them the three words
 * that actually moved. The algorithm is the textbook LCS-based diff — same
 * shape every "track changes" feature uses — kept here rather than pulled in
 * as a package, because the whole comparison is a few dozen lines of a
 * well-known algorithm and a dependency for it would outweigh it.
 */
class VersionComparator
{
    /** Which columns are worth comparing, and how. */
    protected const FIELDS = ['title', 'recipient', 'body'];

    /**
     * @return array{
     *     from: array{version: int, created_at: ?string},
     *     to: array{version: int, created_at: ?string},
     *     fields: array<string, array{changed: bool, diff: array<int, array{op: string, text: string}>}>
     * }
     */
    public function compare(BusinessDocumentVersion $from, BusinessDocumentVersion $to): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $before = (string) ($from->{$field} ?? '');
            $after = (string) ($to->{$field} ?? '');

            $fields[$field] = [
                'changed' => $before !== $after,
                'diff' => $this->diffWords($before, $after),
            ];
        }

        return [
            'from' => ['version' => $from->version_number, 'created_at' => $from->created_at?->toIso8601String()],
            'to' => ['version' => $to->version_number, 'created_at' => $to->created_at?->toIso8601String()],
            'fields' => $fields,
        ];
    }

    /**
     * A word-level diff: unchanged words carry over, removed words are
     * flagged, added words are flagged. Whitespace is kept as its own token so
     * the reconstructed text still reads naturally.
     *
     * @return array<int, array{op: string, text: string}>
     */
    protected function diffWords(string $before, string $after): array
    {
        $a = $this->tokenize($before);
        $b = $this->tokenize($after);

        $lcs = $this->longestCommonSubsequence($a, $b);

        $result = [];
        $i = 0;
        $j = 0;

        foreach ($lcs as [$ai, $bi]) {
            while ($i < $ai) {
                $result[] = ['op' => 'removed', 'text' => $a[$i]];
                $i++;
            }
            while ($j < $bi) {
                $result[] = ['op' => 'added', 'text' => $b[$j]];
                $j++;
            }

            $result[] = ['op' => 'kept', 'text' => $a[$ai]];
            $i++;
            $j++;
        }

        while ($i < count($a)) {
            $result[] = ['op' => 'removed', 'text' => $a[$i]];
            $i++;
        }
        while ($j < count($b)) {
            $result[] = ['op' => 'added', 'text' => $b[$j]];
            $j++;
        }

        return $result;
    }

    /** @return array<int, string> */
    protected function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // Splits on word/non-word boundaries, keeping whitespace as its own
        // token — matches on preg_split's PREG_SPLIT_DELIM_CAPTURE.
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [$text] : $tokens;
    }

    /**
     * Standard dynamic-programming LCS, returning index pairs (ai, bi) of the
     * matched tokens in order. O(n·m) — fine for a document's worth of words,
     * wrong for a novel, and nothing here compares novels.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{0: int, 1: int}>
     */
    protected function longestCommonSubsequence(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return [];
        }

        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }
}
