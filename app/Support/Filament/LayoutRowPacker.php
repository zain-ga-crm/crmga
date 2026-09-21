<?php

namespace App\Support\Filament;

/**
 * S-3.3: the layout contract stores a panel's fields as rows of one or two slots
 * (resources/contracts/layout.schema.json's panels[].rows), but editing that directly
 * would mean the Layout Editor asking "does this field have a neighbour?" for every
 * single field. Instead the editor works with one flat, reorderable list of slots (each
 * carrying its own span of 'half' or 'full') and this class packs that flat order into
 * the contract's row-pairs on save, and unpacks a stored definition's rows back into a
 * flat list to seed the editor. Unpacking is lossless (row grouping doesn't survive a
 * re-pack after edits, but nothing in the contract treats that grouping as meaningful
 * beyond "how halves pair up" -- the flat order is what's authoritative).
 */
final class LayoutRowPacker
{
    /**
     * @param  list<array<array-key, mixed>>  $slots  in display order; each carries 'span' => 'half'|'full'
     * @return list<list<array<array-key, mixed>>> rows, each one or two slots
     */
    public static function pack(array $slots): array
    {
        $rows = [];
        $pendingHalf = null;

        foreach ($slots as $slot) {
            $span = $slot['span'] ?? 'half';

            if ($span === 'full') {
                if ($pendingHalf !== null) {
                    $rows[] = [$pendingHalf];
                    $pendingHalf = null;
                }
                $rows[] = [$slot];

                continue;
            }

            if ($pendingHalf === null) {
                $pendingHalf = $slot;

                continue;
            }

            $rows[] = [$pendingHalf, $slot];
            $pendingHalf = null;
        }

        if ($pendingHalf !== null) {
            $rows[] = [$pendingHalf];
        }

        return $rows;
    }

    /**
     * @param  list<list<array<array-key, mixed>>>  $rows
     * @return list<array<array-key, mixed>>
     */
    public static function unpack(array $rows): array
    {
        $slots = [];

        foreach ($rows as $row) {
            foreach ($row as $slot) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }
}
