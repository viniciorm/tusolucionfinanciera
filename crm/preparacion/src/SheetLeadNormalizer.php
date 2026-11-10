<?php
declare(strict_types=1);

namespace TSF\Sync;

/** Converts one source row without ever modifying or interpreting the CRM data. */
final class SheetLeadNormalizer
{
    private const ALIASES = [
        'meta_id' => ['id'],
        'name' => ['full_name', 'nombre_completo', 'nombre'],
        'email' => ['email', 'correo_electronico', 'correo'],
        'phone' => ['phone', 'phone_number', 'numero_de_telefono', 'telefono'],
        'received_at_raw' => ['created_time'],
        'campaign' => ['campaign_name'],
        'form' => ['form_name'],
        'notification_status' => ['lead_status'],
        'source_assigned_to' => ['assigned_to'],
    ];

    public static function headerKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }

    /** @return array{lead:?array,errors:array} No PII is included in errors. */
    public function normalize(string $sheet, array $headers, array $values): array
    {
        if (!in_array($sheet, ['Hoja 2','Hoja 3','Hoja 4','Hoja 5'], true)) {
            return ['lead'=>null, 'errors'=>['unsupported_sheet']];
        }
        $original = []; $normalized = []; $seen = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') continue;
            $key = self::headerKey($header);
            if (isset($seen[$key])) return ['lead'=>null, 'errors'=>['ambiguous_headers']];
            $seen[$key] = true;
            $value = (string) ($values[$index] ?? '');
            $original[$header] = $value;
            $normalized[$key] = trim($value);
        }
        if (!isset($seen['id'])) return ['lead'=>null, 'errors'=>['missing_id_header']];
        $result = [];
        foreach (self::ALIASES as $target => $aliases) {
            $matches = [];
            foreach ($aliases as $alias) if (($normalized[$alias] ?? '') !== '') $matches[] = $normalized[$alias];
            if (count(array_unique($matches)) > 1) return ['lead'=>null, 'errors'=>['conflicting_'.$target]];
            $result[$target] = $matches[0] ?? '';
        }
        if ($result['meta_id'] === '') return ['lead'=>null, 'errors'=>['missing_id']];
        $result['source_key'] = hash('sha256', $sheet."\0".$result['meta_id']);
        $result['source_sheet'] = $sheet;
        $result['email'] = mb_strtolower($result['email'], 'UTF-8');
        $result['phone_search'] = (string) preg_replace('/\D+/', '', $result['phone']);
        // Assignment is raw source data only. It must be reconciled against explicitly verified users.
        $result['reconciliation_pending'] = true;
        $result['original'] = $original;
        return ['lead'=>$result, 'errors'=>[]];
    }
}
