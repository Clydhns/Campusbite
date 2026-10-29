BEGIN;
INSERT INTO storage.buckets(id,name,public,file_size_limit,allowed_mime_types)
VALUES ('campusbite-images','campusbite-images',true,3145728,ARRAY['image/jpeg','image/png','image/webp']),
    ('campusbite-receipts','campusbite-receipts',false,3145728,ARRAY['image/jpeg','image/png','image/webp']);
-- No anonymous/authenticated object policies: writes and receipt reads are
-- performed only by the authenticated PHP server after its ownership checks.
COMMIT;
