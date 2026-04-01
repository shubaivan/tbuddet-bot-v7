-- ============================================================
-- Fix prod data consistency: prices + property EN translations
-- Run on PROD database
-- ============================================================

BEGIN;

-- 1. Fix products with empty EN price (convert UAH to USD at rate 41)
UPDATE product
SET price = jsonb_set(price, '{en}', to_jsonb(round((price->>'ua')::numeric / 41)::int::text))
WHERE (price->>'en' IS NULL OR price->>'en' = '');

-- 2. Fix property EN translations: property_name
-- Доставка → Delivery
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Доставка' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(prop, '{en,property_name}', '"Delivery"')
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Доставка%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Доставка' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Доставка values: Так → Yes, Ні → No
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Доставка' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
      THEN jsonb_set(prop, '{en,property_value}',
        CASE
          WHEN lower(prop->'ua'->>'property_value') = 'так' THEN '"Yes"'
          WHEN lower(prop->'ua'->>'property_value') = 'ні' THEN '"No"'
          ELSE to_jsonb(prop->'ua'->>'property_value')
        END
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Доставка%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Доставка' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
  );

-- Утеплювач → Thermal insulation
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN lower(prop->'ua'->>'property_name') = 'утеплювач' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(prop, '{en,property_name}', '"Thermal insulation"')
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%теплювач%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE lower(prop->'ua'->>'property_name') = 'утеплювач' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Утеплювач values: Так → Yes, Ні → No
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN lower(prop->'ua'->>'property_name') = 'утеплювач' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
      THEN jsonb_set(prop, '{en,property_value}',
        CASE
          WHEN lower(prop->'ua'->>'property_value') = 'так' THEN '"Yes"'
          WHEN lower(prop->'ua'->>'property_value') = 'ні' THEN '"No"'
          ELSE to_jsonb(prop->'ua'->>'property_value')
        END
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%теплювач%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE lower(prop->'ua'->>'property_name') = 'утеплювач' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
  );

-- Колір → Color
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Колір' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(prop, '{en,property_name}', '"Color"')
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Колір%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Колір' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Колір values: Натуральний → Natural, Білий → White, Індивідуальний → Individual
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Колір' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
      THEN jsonb_set(prop, '{en,property_value}',
        CASE
          WHEN prop->'ua'->>'property_value' = 'Натуральний' THEN '"Natural"'
          WHEN prop->'ua'->>'property_value' = 'Білий' THEN '"White"'
          WHEN prop->'ua'->>'property_value' = 'Індивідуальний' THEN '"Individual"'
          ELSE to_jsonb(prop->'ua'->>'property_value')
        END
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Колір%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Колір' AND (prop->'en'->>'property_value' IS NULL OR prop->'en'->>'property_value' = '')
  );

-- Розмір → Size (copy UA value as-is since dimensions are universal)
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Розмір' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(
        jsonb_set(prop, '{en,property_name}', '"Size"'),
        '{en,property_value}', to_jsonb(prop->'ua'->>'property_value')
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Розмір%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Розмір' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Вага піддону, кг → Pallet weight, kg (copy numeric value as-is)
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'Вага піддону, кг' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(
        jsonb_set(prop, '{en,property_name}', '"Pallet weight, kg"'),
        '{en,property_value}', to_jsonb(prop->'ua'->>'property_value')
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%Вага піддону%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'Вага піддону, кг' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- на піддоні, м² → Per pallet, m² (copy numeric value as-is)
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' = 'на піддоні, м²' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(
        jsonb_set(prop, '{en,property_name}', '"Per pallet, m²"'),
        '{en,property_value}', to_jsonb(prop->'ua'->>'property_value')
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%на піддоні%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' = 'на піддоні, м²' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Long contact info property → translate name, keep phone value
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN prop->'ua'->>'property_name' LIKE '%За детальною інформацією%' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
      THEN jsonb_set(
        jsonb_set(prop, '{en,property_name}', '"For details and delivery terms, contact our manager at the number below"'),
        '{en,property_value}', to_jsonb(prop->'ua'->>'property_value')
      )
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE product_properties::text LIKE '%За детальною інформацією%'
  AND EXISTS (
    SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
    WHERE prop->'ua'->>'property_name' LIKE '%За детальною інформацією%' AND (prop->'en'->>'property_name' IS NULL OR prop->'en'->>'property_name' = '')
  );

-- Also ensure en.property_price_impact is set where missing (copy from ua)
UPDATE product
SET product_properties = (
  SELECT jsonb_agg(
    CASE
      WHEN (prop->'en'->>'property_price_impact' IS NULL OR prop->'en'->>'property_price_impact' = '')
           AND prop->'ua'->>'property_price_impact' IS NOT NULL
      THEN jsonb_set(prop, '{en,property_price_impact}', to_jsonb(prop->'ua'->>'property_price_impact'))
      ELSE prop
    END
  )
  FROM jsonb_array_elements(product_properties) AS prop
)
WHERE EXISTS (
  SELECT 1 FROM jsonb_array_elements(product_properties) AS prop
  WHERE (prop->'en'->>'property_price_impact' IS NULL OR prop->'en'->>'property_price_impact' = '')
    AND prop->'ua'->>'property_price_impact' IS NOT NULL
);

COMMIT;
