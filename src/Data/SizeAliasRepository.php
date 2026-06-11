<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * Per-product size_token → variation/term map. Built during migration from each
 * variable product's real attribute_* meta, and used by the frontend matcher.
 */
final class SizeAliasRepository
{
    public function upsert(SizeAlias $alias): void
    {
        global $wpdb;
        $table = Schema::aliases_table();

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND size_token = %s",
                $alias->product_id,
                $alias->size_token
            )
        );

        $row = [
            'product_id'     => $alias->product_id,
            'size_token'     => $alias->size_token,
            'variation_id'   => $alias->variation_id,
            'attribute_slug' => $alias->attribute_slug,
            'term_value'     => $alias->term_value,
        ];

        if ($existing_id > 0) {
            $wpdb->update($table, $row, ['id' => $existing_id]);
        } else {
            $wpdb->insert($table, $row);
        }
    }

    /** @return array<string,SizeAlias> token => alias */
    public function for_product(int $product_id): array
    {
        global $wpdb;
        $table = Schema::aliases_table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d", $product_id)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->size_token] = new SizeAlias(
                (int) $r->product_id,
                $r->size_token,
                $r->variation_id !== null ? (int) $r->variation_id : null,
                $r->attribute_slug,
                $r->term_value,
            );
        }
        return $out;
    }
}
