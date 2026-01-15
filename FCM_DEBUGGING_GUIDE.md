# FCM Notification Debugging Guide

## Overview
This guide explains how to debug Firebase Cloud Messaging (FCM) notification issues in the JSS Teacher App.

## Problem
If you see "Test sent. Success tokens: 0" in the FCM Admin page, it means notifications are not being delivered successfully.

## Solution
We've added a comprehensive debugging endpoint at `api/devices/fcm_debug.php` that provides detailed diagnostics.

## How to Access the Debug Endpoint

### Step 1: Obtain an API Token
You need an API token to access the debug endpoint. There are two ways to get one:

#### Option A: Via Mobile App Login
1. Log in through the mobile app
2. The login response includes an API token
3. Save this token for use with the debug endpoint

#### Option B: Via API Call
```bash
curl -X POST https://your-domain.com/api/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"username":"your_username","password":"your_password"}'
```

The response will include:
```json
{
  "success": true,
  "data": {
    "token": "your_64_character_api_token_here",
    "expires": "2024-01-02 12:00:00",
    "user": { ... }
  }
}
```

### Step 2: Access the Debug Endpoint

#### Via Web Browser (easiest)
1. Log in to the web portal as super_admin
2. Navigate to Settings > FCM Admin & Diagnostics
3. Your active API tokens will be displayed
4. Click "Open Debug Endpoint" button

#### Via API Call
```bash
curl "https://your-domain.com/api/devices/fcm_debug.php?with_token=1&validate_send=1&api_token=YOUR_TOKEN"
```

Or using Bearer authentication:
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-domain.com/api/devices/fcm_debug.php?with_token=1&validate_send=1"
```

## Debug Endpoint Parameters

- `with_token=1` - Include the full OAuth access token in response (for advanced debugging)
- `validate_send=1` - Attempt a test notification send (validate-only mode, won't actually deliver)
- `device=TOKEN` - Test a specific device token
- `user_id=ID` - Test a specific user's tokens (super_admin only for other users)

## Understanding the Response

The debug endpoint returns a JSON response with these sections:

### 1. Service Account
```json
{
  "service_account": {
    "file_exists": true,
    "file_readable": true,
    "parsed": true,
    "project_id": "jss-teacherapp",
    "client_email": "firebase-adminsdk-fbsvc@jss-teacherapp.iam.gserviceaccount.com",
    "has_private_key": true
  }
}
```

**What to check:**
- All values should be `true` or have valid data
- If `file_exists` is false: Create `config/firebase_service_account.json`
- If `parsed` is false: Check JSON syntax in the service account file
- If `has_private_key` is false: Regenerate the service account key in Firebase Console

### 2. Access Token Generation
```json
{
  "access_token": {
    "generated": true,
    "length": 183
  }
}
```

**What to check:**
- `generated` should be `true`
- If false, check `logs/notifications_log.txt` for errors
- Common issue: service account key has been revoked in Firebase Console

### 3. User Tokens
```json
{
  "user_tokens": {
    "user_id": 123,
    "count": 2,
    "tokens": [
      "dXN1YWxseSBhIHZlcnkgbG...",
      "b25nIEZDTSByZWdpc3RyYX..."
    ]
  }
}
```

**What to check:**
- `count` should be > 0
- If count is 0: User hasn't registered any devices via the mobile app

### 4. Test Send Results (if validate_send=1)
```json
{
  "test_send": {
    "attempted": true,
    "validate_only": true,
    "success_count": 2,
    "result": {
      "ok": true,
      "results": [
        {
          "token": "...",
          "ok": true,
          "http": 200,
          "body": "{\"name\":\"...\"}"
        }
      ]
    }
  }
}
```

**What to check:**
- `success_count` should match token count
- If `ok` is false for a token: Token may be invalid or expired
- Check `http` code: 200 is success, 400/401/403 indicate authentication or permission issues

### 5. Overall Status and Recommendations
```json
{
  "overall_status": "healthy",
  "recommendations": []
}
```

If status is not "healthy", check the `recommendations` array for specific actions to take.

## Common Issues and Solutions

### Issue 1: "Test sent. Success tokens: 0"
**Diagnosis:** Use the debug endpoint to identify the specific problem.

**Common causes:**
1. Service account file missing or invalid
   - Solution: Download a new service account key from Firebase Console
   - Place it at `config/firebase_service_account.json`

2. Access token generation fails
   - Solution: Regenerate the service account key in Firebase Console
   - The old key may have been revoked

3. No device tokens registered
   - Solution: User needs to log in via the mobile app to register their device

4. Invalid device tokens
   - Solution: User needs to log out and log back in via the mobile app

### Issue 2: "invalid_grant" error in logs
**Solution:** Regenerate the service account key in Firebase Console and replace `config/firebase_service_account.json`.

### Issue 3: HTTP 401 or 403 errors
**Diagnosis:** Check that the service account has the correct permissions.

**Solution:** 
1. Go to Firebase Console > Project Settings > Service Accounts
2. Ensure the service account has the "Firebase Cloud Messaging API Admin" role
3. Regenerate and download a new key if needed

### Issue 4: Tokens expire quickly
**Cause:** Device tokens can expire if the app is uninstalled or data is cleared.

**Solution:** User needs to log in again via the mobile app to register a fresh token.

## Monitoring and Logs

### Check Notification Logs
View `logs/notifications_log.txt` for detailed FCM operation logs:
```bash
tail -f logs/notifications_log.txt
```

### Check Send History
The FCM Admin page shows the last 200 send attempts with success/failure status.

### Database Tables
- `fcm_tokens`: Stores user device tokens
- `fcm_send_logs`: Records all notification send attempts

## Security Notes

1. API tokens expire after 24 hours
2. Regular users can only debug their own tokens
3. Super admins can debug any user's tokens
4. Private keys are never exposed in API responses
5. Access to debug endpoint requires valid authentication

## Need More Help?

1. Check `logs/notifications_log.txt` for detailed error messages
2. Check `logs/php_errors.log` for PHP errors
3. Verify Firebase service account has correct permissions in Firebase Console
4. Ensure Firebase Cloud Messaging API is enabled in Google Cloud Console
