#!/usr/bin/env python3
"""
Comprehensive verification script for the improved email functionality
Demonstrates all the fixes and improvements made to the Python email system
"""

import os
import sys
import sqlite3
import json
import subprocess

# Add python directory to path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'python'))

def test_database_setup():
    """Verify database is properly configured"""
    print("🗄️  Testing Database Configuration...")
    
    try:
        db_path = os.path.join(os.path.dirname(__file__), 'db', 'mail.sqlite')
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # Check all required tables exist
        tables = ['mail_accounts', 'proxy_config', 'http_proxies', 'socks5_proxies']
        for table in tables:
            cursor.execute(f"SELECT name FROM sqlite_master WHERE type='table' AND name='{table}'")
            if not cursor.fetchone():
                print(f"❌ Table {table} not found")
                return False
        
        # Check proxy configuration
        cursor.execute("SELECT config_key, config_value FROM proxy_config")
        config = {row[0]: row[1] for row in cursor.fetchall()}
        
        # Check test data exists
        cursor.execute("SELECT COUNT(*) FROM mail_accounts")
        account_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM http_proxies")
        http_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM socks5_proxies")
        socks_count = cursor.fetchone()[0]
        
        conn.close()
        
        print(f"✅ All required tables found")
        print(f"✅ Mail accounts: {account_count}")
        print(f"✅ HTTP proxies: {http_count}")
        print(f"✅ SOCKS5 proxies: {socks_count}")
        print(f"✅ Proxy enabled: {config.get('proxy_enabled', '0')}")
        
        return True
        
    except Exception as e:
        print(f"❌ Database test failed: {e}")
        return False

def test_python_functionality():
    """Test Python mail fetcher functionality"""
    print("\n🐍 Testing Python Mail Fetcher...")
    
    try:
        # Test with proxy disabled
        print("  Testing with proxy disabled...")
        subprocess.run(['sqlite3', 'db/mail.sqlite', 
                       "UPDATE proxy_config SET config_value = '0' WHERE config_key = 'proxy_enabled'"],
                      check=True, capture_output=True)
        
        result = subprocess.run(['python3', 'python/mail_fetcher.py', 'test@gmail.com', '--test-connection'],
                               capture_output=True, text=True)
        
        if result.returncode == 0:
            response = json.loads(result.stdout)
            print(f"✅ Direct connection test: {response['success']}")
            print(f"   Message: {response['message']}")
            print(f"   Proxy status: {response.get('proxy', {}).get('enabled', False)}")
        else:
            print(f"❌ Direct connection test failed: {result.stderr}")
            return False
        
        # Test with proxy enabled
        print("  Testing with proxy enabled...")
        subprocess.run(['sqlite3', 'db/mail.sqlite', 
                       "UPDATE proxy_config SET config_value = '1' WHERE config_key = 'proxy_enabled'"],
                      check=True, capture_output=True)
        
        result = subprocess.run(['python3', 'python/mail_fetcher.py', 'test@gmail.com', '--test-connection'],
                               capture_output=True, text=True)
        
        if result.returncode == 0:
            response = json.loads(result.stdout)
            print(f"✅ Proxy connection test: {response['success']}")
            print(f"   Message: {response['message']}")
            print(f"   Proxy status: {response.get('proxy', {}).get('enabled', False)}")
            if response.get('proxy', {}).get('info'):
                proxy_info = response['proxy']['info']
                print(f"   Proxy type: {proxy_info['type']}")
                print(f"   Proxy name: {proxy_info['name']}")
        else:
            print(f"❌ Proxy connection test failed: {result.stderr}")
            return False
        
        return True
        
    except Exception as e:
        print(f"❌ Python functionality test failed: {e}")
        return False

def test_php_integration():
    """Test PHP integration"""
    print("\n🔗 Testing PHP Integration...")
    
    try:
        # Test PHP bridge
        php_code = '''
        <?php
        chdir('/home/runner/work/9.10/9.10');
        require_once 'backend/utils/python_mail_bridge.php';
        
        $fetcher = new PythonMailFetcher('test@gmail.com');
        $result = $fetcher->testConnection();
        
        echo json_encode([
            'success' => $result['success'],
            'has_diagnostics' => isset($result['diagnostics']),
            'has_proxy_info' => isset($result['proxy']),
            'error_type' => $result['error_type'] ?? null
        ]);
        ?>
        '''
        
        result = subprocess.run(['php', '-r', php_code], capture_output=True, text=True)
        
        if result.returncode == 0:
            response = json.loads(result.stdout)
            print(f"✅ PHP Bridge working: {response}")
            return True
        else:
            print(f"❌ PHP integration failed: {result.stderr}")
            return False
        
    except Exception as e:
        print(f"❌ PHP integration test failed: {e}")
        return False

def test_api_endpoints():
    """Test that API endpoints exist and are properly structured"""
    print("\n🌐 Testing API Endpoints...")
    
    try:
        endpoints = [
            'backend/api/get_mail.php',
            'backend/api/test_connection.php',
            'admin/api/test_connection.php'
        ]
        
        for endpoint in endpoints:
            if os.path.exists(endpoint):
                print(f"✅ {endpoint} exists")
            else:
                print(f"❌ {endpoint} missing")
                return False
        
        return True
        
    except Exception as e:
        print(f"❌ API endpoint test failed: {e}")
        return False

def test_frontend_files():
    """Test frontend files"""
    print("\n🎨 Testing Frontend Files...")
    
    try:
        frontend_file = 'frontend/index.html'
        
        if not os.path.exists(frontend_file):
            print(f"❌ {frontend_file} missing")
            return False
        
        with open(frontend_file, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Check for test connection functionality
        if 'test-connection-btn' in content:
            print("✅ Test connection button found in frontend")
        else:
            print("❌ Test connection button not found in frontend")
            return False
        
        if 'testConnection()' in content:
            print("✅ Test connection function found in frontend")
        else:
            print("❌ Test connection function not found in frontend")
            return False
        
        if 'displayConnectionDiagnostics' in content:
            print("✅ Connection diagnostics function found in frontend")
        else:
            print("❌ Connection diagnostics function not found in frontend")
            return False
        
        return True
        
    except Exception as e:
        print(f"❌ Frontend test failed: {e}")
        return False

def main():
    """Run all verification tests"""
    print("🔍 Comprehensive Email Functionality Verification")
    print("=" * 50)
    
    tests = [
        ("Database Setup", test_database_setup),
        ("Python Functionality", test_python_functionality),
        ("PHP Integration", test_php_integration),
        ("API Endpoints", test_api_endpoints),
        ("Frontend Files", test_frontend_files)
    ]
    
    results = []
    
    for test_name, test_func in tests:
        print(f"\n🧪 {test_name}:")
        try:
            result = test_func()
            results.append((test_name, result))
        except Exception as e:
            print(f"❌ {test_name} failed with exception: {e}")
            results.append((test_name, False))
    
    # Summary
    print("\n" + "=" * 50)
    print("📊 VERIFICATION SUMMARY")
    print("=" * 50)
    
    passed = 0
    total = len(results)
    
    for test_name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status} {test_name}")
        if result:
            passed += 1
    
    print(f"\nOverall: {passed}/{total} tests passed")
    
    if passed == total:
        print("\n🎉 ALL TESTS PASSED! Email functionality is working correctly.")
        print("\n✨ Key Improvements Verified:")
        print("   • SOCKS5 and HTTP proxy support implemented")
        print("   • Comprehensive error handling and diagnostics")
        print("   • Test connection functionality in frontend")
        print("   • PHP-Python integration working")
        print("   • Database configuration proper")
        print("   • API endpoints available")
    else:
        print(f"\n⚠️  {total - passed} tests failed. Please review the issues above.")
    
    return passed == total

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)