<?php

namespace App\Services;

class PublicDnsLookup
{
    /**
     * @return bool|null true = есть A/AAAA, false = NXDOMAIN, null = не удалось спросить DNS
     */
    public function domainExists(string $host): ?bool
    {
        $host = $this->normalizeHost($host);

        if ($host === '') {
            return false;
        }

        foreach (['8.8.8.8', '1.1.1.1'] as $server) {
            $a = $this->query($host, $server, 1);

            if ($a === true) {
                return true;
            }

            if ($a === false) {
                return false;
            }

            $aaaa = $this->query($host, $server, 28);

            if ($aaaa === true) {
                return true;
            }

            if ($aaaa === false) {
                return false;
            }
        }

        return null;
    }

    protected function query(string $host, string $server, int $type): ?bool
    {
        $id = random_int(0, 65535);
        $packet = $this->buildQuery($host, $id, $type);
        $socket = @fsockopen('udp://'.$server, 53, $errno, $errstr, 2);

        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, $packet);
        $response = fread($socket, 512);
        fclose($socket);

        if (! is_string($response) || strlen($response) < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($response, 0, 12));

        if (($header['id'] ?? null) !== $id) {
            return null;
        }

        $rcode = ($header['flags'] ?? 0) & 0x0F;

        if ($rcode === 3) {
            return false;
        }

        // SERVFAIL: NS недоступны, часто выключенный VPS
        if ($rcode === 2) {
            return false;
        }

        if ($rcode !== 0) {
            return null;
        }

        return ($header['ancount'] ?? 0) > 0;
    }

    protected function buildQuery(string $host, int $id, int $type): string
    {
        $header = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);
        $qname = '';

        foreach (explode('.', $host) as $label) {
            $qname .= chr(strlen($label)).$label;
        }

        $qname .= "\0";

        return $header.$qname.pack('nn', $type, 1);
    }

    protected function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii !== false) {
                return $ascii;
            }
        }

        return $host;
    }
}
