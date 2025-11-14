API Overview (Teacher Mobile App)

Base URL: /api/
All responses JSON with envelope:
{"success":true,"data":{...}} or {"success":false,"error":"message","code":400}

Auth Strategy:
POST /api/auth/login -> returns {token, user:{id,name,role}}
Client sends token via Authorization: Bearer <token>
Tokens stored in api_tokens table (random 64 hex) exp default 24h.

Endpoints (Phase 1):
1. POST /auth/login
2. GET  /teacher/duties?days=7
3. GET  /exam/seat_plans?date=YYYY-MM-DD
4. GET  /exam/attendance?date=YYYY-MM-DD&plan_id=PL&room_id=RID
5. POST /exam/attendance (JSON body {date,plan_id,room_id,entries:[{student_id,status}]})
6. GET  /marks/subjects?exam_id=E&class_id=C
7. POST /marks/submit (JSON body {exam_id,class_id,subject_id,marks:[{student_id,mark}]})

Bootstrap Flow:
api/bootstrap.php -> loads config/db, parse Authorization header, validate token, populate $authUser.

Errors: Always include HTTP code and error message. Non-auth errors 400; auth failures 401; not found 404.

Pagination (future): Add page, per_page query params; include meta: {page,per_page,total}.

Rate Limiting (future): Simple IP + token request count per minute stored in transient table or memory.

Security Notes:
- Use prepared statements.
- Validate date format with regex ^\d{4}-\d{2}-\d{2}$.
- Only allow teacher role to access teacher endpoints.
- Marks submission restricted (role teacher or super_admin by policy decision).

SQL (api_tokens):
CREATE TABLE api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role VARCHAR(32) NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  expires DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ip VARCHAR(64) NULL,
  INDEX(user_id), INDEX(expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
