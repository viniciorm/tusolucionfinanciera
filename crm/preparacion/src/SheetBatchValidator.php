<?php
declare(strict_types=1);
namespace TSF\Sync;

/** Validates a full worksheet before any storage write; duplicate IDs are never silently accepted. */
final class SheetBatchValidator
{
    public function __construct(private readonly SheetLeadNormalizer $normalizer) {}

    public function validate(string $sheet, array $headers, array $rows): array
    {
        $valid = []; $rejected = []; $keys = []; $conflicts = [];
        foreach ($rows as $offset => $values) {
            if (!is_array($values)) { $rejected[] = ['row'=>$offset+2, 'errors'=>['invalid_row']]; continue; }
            if (!array_filter($values, static fn ($v) => trim((string) $v) !== '')) continue;
            $result = $this->normalizer->normalize($sheet, $headers, $values);
            if ($result['errors']) { $rejected[] = ['row'=>$offset+2, 'errors'=>$result['errors']]; continue; }
            $lead = $result['lead']; $key = $lead['source_key'];
            if (isset($keys[$key])) { $conflicts[$key] = true; $rejected[] = ['row'=>$offset+2, 'errors'=>['duplicate_source_id']]; continue; }
            $keys[$key] = $offset+2; $valid[$key] = $lead;
        }
        foreach ($conflicts as $key => $_) {
            unset($valid[$key]);
            $rejected[] = ['row'=>$keys[$key], 'errors'=>['duplicate_source_id']];
        }
        return ['valid'=>array_values($valid), 'rejected'=>$rejected];
    }
}
