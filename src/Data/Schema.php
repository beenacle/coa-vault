<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * Table definitions. DDL is formatted to dbDelta's strict rules:
 * one column per line, two spaces after PRIMARY KEY, KEY (not INDEX),
 * $wpdb->prefix, and get_charset_collate() (utf8mb4 mandatory so raw
 * Cyrillic / '&' legacy values survive in *_raw / extra columns).
 */
final class Schema
{
    public static function records_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'coa_vault_records';
    }

    public static function characteristics_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'coa_vault_characteristics';
    }

    public static function aliases_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'coa_vault_size_aliases';
    }

    /**
     * @return string[] One CREATE TABLE statement per table.
     */
    public static function ddl(): array
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $records = self::records_table();
        $chars   = self::characteristics_table();
        $aliases = self::aliases_table();

        $sql   = [];
        $sql[] = "CREATE TABLE {$records} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NULL DEFAULT NULL,
  size_token varchar(32) NOT NULL DEFAULT '',
  batch varchar(190) NOT NULL DEFAULT '',
  batch_inferred tinyint(1) NOT NULL DEFAULT 0,
  lab_slug varchar(64) NOT NULL DEFAULT '',
  lab_label varchar(128) NOT NULL DEFAULT '',
  analysis_date date NULL DEFAULT NULL,
  analysis_date_raw varchar(64) NOT NULL DEFAULT '',
  purity_pct decimal(7,4) NULL DEFAULT NULL,
  mass_mg decimal(10,4) NULL DEFAULT NULL,
  report_file_id bigint(20) unsigned NULL DEFAULT NULL,
  report_url varchar(2048) NOT NULL DEFAULT '',
  verify_url varchar(2048) NOT NULL DEFAULT '',
  report_kind varchar(8) NOT NULL DEFAULT 'none',
  sort_order int(11) NOT NULL DEFAULT 0,
  applies_all_sizes tinyint(1) NOT NULL DEFAULT 0,
  source_site varchar(64) NOT NULL DEFAULT '',
  source_type varchar(32) NOT NULL DEFAULT '',
  source_post_id bigint(20) unsigned NULL DEFAULT NULL,
  source_row_index int(11) NULL DEFAULT NULL,
  source_hash char(40) NOT NULL,
  migration_run_id bigint(20) unsigned NULL DEFAULT NULL,
  source_present tinyint(1) NOT NULL DEFAULT 1,
  extra longtext NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash),
  KEY product_size (product_id, size_token),
  KEY variation (variation_id),
  KEY product_date (product_id, analysis_date),
  KEY lab_slug (lab_slug),
  KEY purity (purity_pct)
) {$charset};";

        $sql[] = "CREATE TABLE {$chars} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  coa_id bigint(20) unsigned NOT NULL,
  name_slug varchar(64) NOT NULL,
  name_label varchar(128) NOT NULL DEFAULT '',
  value_num decimal(18,6) NULL DEFAULT NULL,
  value_text varchar(255) NOT NULL DEFAULT '',
  unit varchar(16) NOT NULL DEFAULT '',
  position int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY coa_id (coa_id),
  KEY name_value (name_slug, value_num)
) {$charset};";

        $sql[] = "CREATE TABLE {$aliases} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  size_token varchar(32) NOT NULL,
  variation_id bigint(20) unsigned NULL DEFAULT NULL,
  attribute_slug varchar(64) NOT NULL DEFAULT '',
  term_value varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY product_token (product_id, size_token),
  KEY variation (variation_id)
) {$charset};";

        return $sql;
    }
}
