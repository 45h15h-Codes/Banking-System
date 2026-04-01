#!/bin/bash
set -e

BASE_URL="http://localhost:8001/api/v1/auth"
INTERNAL_URL="http://localhost:8001/api/v1/internal/auth"

echo "======================================"
echo "1. Testing Registration"
echo "======================================"
REGISTER_RESPONSE=$(curl -s -X POST $BASE_URL/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Test User","email":"test1@bankcore.test","password":"Password@123","password_confirmation":"Password@123","phone":"+91-9999999999"}')

echo $REGISTER_RESPONSE | jq .

echo ""
echo "======================================"
echo "2. Testing Login (Admin User)"
echo "======================================"
LOGIN_RESPONSE=$(curl -s -X POST $BASE_URL/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@bankcore.test","password":"Password@123"}')

echo $LOGIN_RESPONSE | jq .

ACCESS_TOKEN=$(echo $LOGIN_RESPONSE | jq -r .data.token)
REFRESH_TOKEN=$(echo $LOGIN_RESPONSE | jq -r .data.refresh_token)

echo ""
echo "Extracted Token: $ACCESS_TOKEN"

echo ""
echo "======================================"
echo "3. Testing /me Endpoint (Protected)"
echo "======================================"
ME_RESPONSE=$(curl -s -X GET $BASE_URL/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $ACCESS_TOKEN")

echo $ME_RESPONSE | jq .

echo ""
echo "======================================"
echo "4. Testing /refresh Endpoint"
echo "======================================"
REFRESH_RESPONSE=$(curl -s -X POST $BASE_URL/refresh \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}")

echo $REFRESH_RESPONSE | jq .

NEW_ACCESS_TOKEN=$(echo $REFRESH_RESPONSE | jq -r .data.token)

echo ""
echo "======================================"
echo "5. Testing /internal/auth/verify"
echo "======================================"
VERIFY_RESPONSE=$(curl -s -X GET $INTERNAL_URL/verify \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $NEW_ACCESS_TOKEN" \
  -H "X-Internal-Token: bankcore-internal-secret-2026")

echo $VERIFY_RESPONSE | jq .

echo ""
echo "======================================"
echo "6. Testing Logout"
echo "======================================"
LOGOUT_RESPONSE=$(curl -s -X POST $BASE_URL/logout \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $NEW_ACCESS_TOKEN")

echo $LOGOUT_RESPONSE | jq .

echo ""
echo "======================================"
echo "7. Testing /me After Logout (Should be 401)"
echo "======================================"
ME_AFTER_LOGOUT=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X GET $BASE_URL/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $NEW_ACCESS_TOKEN")

echo "$ME_AFTER_LOGOUT"
