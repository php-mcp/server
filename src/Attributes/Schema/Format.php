<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes\Schema;

/**
 * Common string formats supported by JSON Schema
 */
class Format
{
    // String formats
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATE_TIME = 'date-time';
    public const DURATION = 'duration';
    public const EMAIL = 'email';
    public const IDN_EMAIL = 'idn-email';
    public const HOSTNAME = 'hostname';
    public const IDN_HOSTNAME = 'idn-hostname';
    public const IPV4 = 'ipv4';
    public const IPV6 = 'ipv6';
    public const URI = 'uri';
    public const URI_REFERENCE = 'uri-reference';
    public const IRI = 'iri';
    public const IRI_REFERENCE = 'iri-reference';
    public const URI_TEMPLATE = 'uri-template';
    public const JSON_POINTER = 'json-pointer';
    public const RELATIVE_JSON_POINTER = 'relative-json-pointer';
    public const REGEX = 'regex';
    public const UUID = 'uuid';
}
