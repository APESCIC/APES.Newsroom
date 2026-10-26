<?php

namespace App\Services\Mail;

class DnsTxtResolver
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_map(
            fn (array $record) => (string) ($record['txt'] ?? implode('', $record['entries'] ?? [])),
            $records,
        ));
    }
}
