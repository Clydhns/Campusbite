BEGIN;

-- Optional student profile photo, so kitchen staff can hand an order to the right person.
-- Files live in the private campusbite-receipts bucket under avatars/ and are only served by
-- profile_photo.php after checking the viewer is the student, that kitchen's staff, or an admin.
ALTER TABLE public.users ADD COLUMN avatar_path varchar(255);

COMMIT;
