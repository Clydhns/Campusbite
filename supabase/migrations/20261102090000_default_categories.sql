-- Starting food categories so staff can add food on a new deployment.
-- Administrators manage the list afterwards in admin_categories.php. Safe to re-run:
-- names that already exist (case-insensitive) are skipped.
INSERT INTO public.categories (category_name, description)
SELECT defaults.category_name, defaults.description
FROM (VALUES
    ('Rice Meals', 'Rice meals and viands'),
    ('Snacks', 'Light bites, bread and sandwiches'),
    ('Beverages', 'Drinks, juices and bottled water'),
    ('Desserts', 'Sweets and desserts')
) AS defaults(category_name, description)
WHERE NOT EXISTS (
    SELECT 1 FROM public.categories existing
    WHERE lower(existing.category_name) = lower(defaults.category_name)
)
ORDER BY defaults.category_name;
