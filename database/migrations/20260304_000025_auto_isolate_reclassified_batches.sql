-- P1-09
-- When a guarantee changes test classification (is_test_data), keep batch purity intact
-- by moving that guarantee occurrence to an isolated batch if the original batch becomes mixed.

CREATE OR REPLACE FUNCTION wbgl_trg_enforce_batch_purity_guarantees()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    rec RECORD;
    v_test_count INTEGER := 0;
    v_real_count INTEGER := 0;
    v_target_batch TEXT := '';
    v_mode_suffix TEXT := '';
BEGIN
    IF NEW.is_test_data IS NOT DISTINCT FROM OLD.is_test_data THEN
        RETURN NULL;
    END IF;

    v_mode_suffix := CASE WHEN COALESCE(NEW.is_test_data, 0) = 1 THEN 'test' ELSE 'real' END;

    FOR rec IN
        SELECT DISTINCT o.batch_identifier
        FROM guarantee_occurrences o
        WHERE o.guarantee_id = NEW.id
    LOOP
        SELECT
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 1 THEN o.guarantee_id END),
            COUNT(DISTINCT CASE WHEN COALESCE(g.is_test_data, 0) = 0 THEN o.guarantee_id END)
        INTO v_test_count, v_real_count
        FROM guarantee_occurrences o
        JOIN guarantees g ON g.id = o.guarantee_id
        WHERE o.batch_identifier = rec.batch_identifier;

        IF v_test_count > 0 AND v_real_count > 0 THEN
            v_target_batch := rec.batch_identifier || '_reclass_' || NEW.id::text || '_' || v_mode_suffix;

            UPDATE guarantee_occurrences
            SET batch_identifier = v_target_batch,
                batch_type = 'reclass'
            WHERE guarantee_id = NEW.id
              AND batch_identifier = rec.batch_identifier;

            INSERT INTO batch_metadata (import_source, batch_name, batch_notes, status)
            VALUES (
                v_target_batch,
                CASE
                    WHEN v_mode_suffix = 'test' THEN 'دفعة معاد تصنيفها: اختبار'
                    ELSE 'دفعة معاد تصنيفها: حقيقي'
                END,
                'Generated automatically to preserve batch purity during classification change',
                'completed'
            )
            ON CONFLICT (import_source) DO NOTHING;

            PERFORM wbgl_assert_batch_purity(rec.batch_identifier);
            PERFORM wbgl_assert_batch_purity(v_target_batch);
        ELSE
            PERFORM wbgl_assert_batch_purity(rec.batch_identifier);
        END IF;
    END LOOP;

    RETURN NULL;
END;
$$;
