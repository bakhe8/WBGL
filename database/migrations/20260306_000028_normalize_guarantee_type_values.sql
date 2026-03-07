-- Canonicalize guarantee type values in raw_data->type to prevent duplicate buckets
-- in analytics caused by case/locale variants (e.g. Final/FINAL/نهائي).

UPDATE guarantees
SET raw_data = (
    jsonb_set(
        raw_data::jsonb,
        '{type}',
        to_jsonb(
            CASE
                WHEN COALESCE(NULLIF(BTRIM(raw_data->>'type'), ''), '') = '' THEN 'غير محدد'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(PERFORMANCE)'
                     OR BTRIM(raw_data->>'type') ~* 'حسن\\s*تنفيذ'
                    THEN 'حسن تنفيذ'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(FINAL)'
                     OR BTRIM(raw_data->>'type') ~* '(نهائي|نهائى|نهائ|أخير|اخير)'
                    THEN 'نهائي'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(INITIAL|BID|TENDER|PROVISIONAL)'
                     OR BTRIM(raw_data->>'type') ~* '(ابتدائي|إبتدائي|أولي|اولي)'
                    THEN 'ابتدائي'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(ADVANCE|ADV)'
                     OR BTRIM(raw_data->>'type') ~* '(دفعة\\s*مقدمة|مقدمة)'
                    THEN 'دفعة مقدمة'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(RETENTION)'
                     OR BTRIM(raw_data->>'type') ~* '(محجوز)'
                    THEN 'محجوز ضمان'
                WHEN UPPER(BTRIM(raw_data->>'type')) ~ '(MAINTENANCE)'
                     OR BTRIM(raw_data->>'type') ~* '(صيانة)'
                    THEN 'صيانة'
                ELSE BTRIM(raw_data->>'type')
            END
        ),
        true
    )::json
)
WHERE raw_data IS NOT NULL
  AND (
      NOT (raw_data::jsonb ? 'type')
      OR COALESCE(NULLIF(BTRIM(raw_data->>'type'), ''), '') = ''
      OR UPPER(BTRIM(raw_data->>'type')) ~ '(PERFORMANCE|FINAL|INITIAL|BID|TENDER|PROVISIONAL|ADVANCE|ADV|RETENTION|MAINTENANCE)'
      OR BTRIM(raw_data->>'type') ~* '(حسن\\s*تنفيذ|نهائي|نهائى|نهائ|أخير|اخير|ابتدائي|إبتدائي|أولي|اولي|دفعة\\s*مقدمة|مقدمة|محجوز|صيانة)'
  );
