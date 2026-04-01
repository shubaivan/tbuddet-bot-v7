-- Run these SQL commands on PROD after deploy
-- Date: 2026-04-01

-- 1. Delete zero-price products (no orders linked to them)
DELETE FROM product_category WHERE product_id IN (
  SELECT id FROM product WHERE (price->>'ua')::int = 0
);
DELETE FROM files WHERE product_id IN (
  SELECT id FROM product WHERE (price->>'ua')::int = 0
);
DELETE FROM product WHERE (price->>'ua')::int = 0;

-- 2. Add EN translations to paving tiles
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Brukway"') WHERE id = 144;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Megapolis"') WHERE id = 145;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Square 400"') WHERE id = 146;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Monolith"') WHERE id = 147;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Symphony"') WHERE id = 148;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"City Stone"') WHERE id = 149;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Brick without chamfer"') WHERE id = 150;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Old Town 45(H)"') WHERE id = 160;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Old Town 60(H)"') WHERE id = 161;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Rhombus"') WHERE id = 162;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Brick 45(H)"') WHERE id = 163;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Brick 60(H)"') WHERE id = 164;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Parking Grid"') WHERE id = 165;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Tavr"') WHERE id = 166;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Falka 100(H)"') WHERE id = 167;
UPDATE product SET product_name = jsonb_set(product_name, '{en}', '"Falka 120(H)"') WHERE id = 168;

-- Tactile tiles "Attention"
UPDATE product SET product_name = jsonb_set(product_name, '{en}', to_jsonb(('Tactile tile "Attention" ' || substring(product_name->>'ua' from '(\d+х\d+х\d+)'))::text)) WHERE id IN (151, 152, 153, 154);

-- Tactile tiles "Movement"
UPDATE product SET product_name = jsonb_set(product_name, '{en}', to_jsonb(('Tactile tile "Movement" ' || substring(product_name->>'ua' from '(\d+х\d+х\d+)'))::text)) WHERE id IN (155, 156, 157, 158);

-- EN descriptions for paving tiles
UPDATE product SET description = jsonb_set(description, '{en}', to_jsonb('Paving tiles are a modern and practical solution for pedestrian zones, courtyards, garden paths and squares. They combine aesthetics and durability, creating a comfortable and long-lasting surface. Advantages: High strength and load resistance. Frost and moisture resistance. Easy installation and maintenance. Variety of shapes, colors and textures. Eco-friendliness and durability. Paving tiles are ideal for urban sidewalks, private courtyards, garden paths, and recreation areas.'::text))
WHERE id IN (144, 145, 146, 147, 148, 149, 150, 160, 161, 162, 163, 164, 165, 166, 167, 168);

-- EN descriptions for tactile tiles "Attention"
UPDATE product SET description = jsonb_set(description, '{en}', to_jsonb('Tactile tile "Attention" is designed to inform visually impaired people about approaching a potentially dangerous zone or change in direction. Its relief surface with round bumps is easily felt by a cane or sole of a shoe, allowing timely warning about obstacles or boundaries. Key features: Function: warning, signals danger or need to stop. Surface: with domed relief bumps. Application: pedestrian crossings, public transport stops, stairs, ramps, entrances to public buildings, railway and metro platforms. Material: wear-resistant, non-slip, weather-resistant. Purpose: improving safety and ensuring a barrier-free environment.'::text))
WHERE id IN (151, 152, 153, 154);

-- EN descriptions for tactile tiles "Movement"
UPDATE product SET description = jsonb_set(description, '{en}', to_jsonb('Tactile tile "Movement" is used for orientation of visually impaired people during movement. Its surface has longitudinal relief strips that indicate the direction of movement and help navigate safely. Key features: Function: orientational, sets the direction of movement. Surface: with longitudinal relief strips. Application: sidewalks, pedestrian zones, crossings, interiors of public buildings, railway and metro platforms. Material: wear-resistant, non-slip, weather-resistant. Purpose: creating a barrier-free environment, facilitating orientation and safe movement.'::text))
WHERE id IN (155, 156, 157, 158);
