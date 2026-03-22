#!/bin/bash

# Laravel Passport OAuth2 Setup Script
# This script completes Passport setup for your API

set -e

echo "================================"
echo "Laravel Passport Setup Script"
echo "================================"
echo ""

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: artisan file not found. Please run this script from your Laravel root directory."
    exit 1
fi

echo "Step 1: Running migrations..."
php artisan migrate

echo ""
echo "Step 2: Generating encryption keys..."
php artisan passport:keys --force

echo ""
echo "Step 3: Creating OAuth2 client for password grant..."
echo ""
echo "⚠️  IMPORTANT: A new window will open for client creation."
echo "When prompted:"
echo "  - Choose option: 2 (Password grant client)"
echo "  - Enter a name (e.g., 'External System API')"
echo ""
read -p "Press Enter to continue..."

php artisan passport:client --password

echo ""
echo "✅ Passport setup complete!"
echo ""
echo "📋 Next steps:"
echo "  1. Save the Client ID and Client Secret above"
echo "  2. Share with external systems for authentication"
echo "  3. Read PASSPORT_OAUTH2_GUIDE.md for API documentation"
echo "  4. Test with curl:"
echo ""
echo "     curl -X POST http://localhost:8000/api/oauth/issue-token \\"
echo "       -H 'Content-Type: application/json' \\"
echo "       -d '{\"user_id\":\"admin001\",\"password\":\"password\"}'"
echo ""
echo "================================"
echo "Setup Complete!"
echo "================================"
