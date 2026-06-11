<?php

declare(strict_types=1);

namespace CoaVault\Support;

/**
 * Controlled vocabularies shared by the whole plugin (migration, admin, frontend).
 * One source of truth so every site enforces the same labs and measurement names.
 */
final class Vocab
{
    /** Canonical lab slug => display label. */
    public const LABS = [
        'janoshik'    => 'Janoshik',
        'chromate'    => 'Chromate',
        'trustpointe' => 'TrustPointe',
        'accumark'    => 'AccuMark Labs',
        'bt_labs'     => 'BT Lab Testing',
    ];

    /**
     * Raw lab text (case/whitespace folded) => canonical slug.
     * Extend as new aliases are discovered in the wild.
     */
    public const LAB_ALIASES = [
        'janoshik'              => 'janoshik',
        'chromate'              => 'chromate',
        'trustpointe analytics llc' => 'trustpointe',
        'trustpointe analytics' => 'trustpointe',
        'trustpointe'           => 'trustpointe',
        'trustpoint analytics'  => 'trustpointe',
        'trustpoint'            => 'trustpointe',
        'accumark labs'         => 'accumark',
        'accumark'              => 'accumark',
        'bt lab testing'        => 'bt_labs',
        'btlabtesting'          => 'bt_labs',
        'btlabs'                => 'bt_labs',
        'bt labs'               => 'bt_labs',
    ];

    /**
     * Verify/report URL host => canonical lab slug, for inferring the lab from a
     * report link when no explicit lab field exists. Matched against the URL host
     * exactly or as a parent domain (so verify.janoshik.com → janoshik.com).
     */
    public const LAB_HOSTS = [
        'janoshik.com'        => 'janoshik',
        'chromate.org'        => 'chromate',
        'trustpointelims.com' => 'trustpointe',
        'accumarklabs.com'    => 'accumark',
        'btlabtesting.com'    => 'bt_labs',
    ];

    /** Canonical measurement name slug => display label. */
    public const MEASURES = [
        'purity' => 'Purity',
        'mass'   => 'Mass',
    ];

    /**
     * Raw measurement label (folded) => canonical name slug.
     * Covers every variant seen across the 9 legacy sites.
     */
    public const MEASURE_ALIASES = [
        'avg. purity' => 'purity',
        'avg purity'  => 'purity',
        'purity'      => 'purity',
        'avg. mass'   => 'mass',
        'avg mass'    => 'mass',
        'mass'        => 'mass',
        'weight'      => 'mass',
    ];

    /** Allowed measurement units. Anything else is kept verbatim + flagged. */
    public const UNITS = ['%', 'mg'];
}
