# jsssms
Jorepukuria Secondary School

## Exam Duty Allocation: DB Constraints

To hard-enforce "one room per plan/date" and "one teacher per plan/date" for exam invigilation duties, you have two options:

- Run the migration SQL: `Database/migrations/2025-11-15_add_unique_invigilation_indexes.sql` in MySQL.
- Or call the admin endpoint (requires super_admin token): `/api/exam/ensure_invigilation_schema.php` — it creates the table (if missing), cleans duplicates, and adds unique indexes.

These constraints align with the app/API logic and ensure data consistency.
