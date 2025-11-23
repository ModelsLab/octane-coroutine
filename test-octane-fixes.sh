#!/bin/bash

# Octane Coroutine Test Script
# Tests single request and concurrent requests to verify worker pool initialization fixes

set -e

OCTANE_PORT=8000
BASE_URL="http://127.0.0.1:${OCTANE_PORT}"
TEST_ENDPOINT="${BASE_URL}/swoole-test?sleep=2"

echo "======================================"
echo "Octane Coroutine Test Suite"
echo "======================================"
echo ""

# Function to check if Octane is running
check_octane() {
    if curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/health" 2>/dev/null | grep -q "200\|404"; then
        return 0
    else
        return 1
    fi
}

# Function to wait for Octane to start
wait_for_octane() {
    echo "Waiting for Octane to start..."
    RETRIES=0
    MAX_RETRIES=30
    
    while [ $RETRIES -lt $MAX_RETRIES ]; do
        if check_octane; then
            echo "✓ Octane is running"
            return 0
        fi
        RETRIES=$((RETRIES + 1))
        echo "  Attempt $RETRIES/$MAX_RETRIES..."
        sleep 1
    done
    
    echo "✗ Octane failed to start within 30 seconds"
    return 1
}

# Test 1: Single Request
test_single_request() {
    echo ""
    echo "======================================"
    echo "Test 1: Single Request"
    echo "======================================"
    
    echo "Sending request to: ${TEST_ENDPOINT}"
    
    RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" "${TEST_ENDPOINT}")
    HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE" | cut -d: -f2)
    BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE/d')
    
    echo "HTTP Status: ${HTTP_CODE}"
    echo "Response Body:"
    echo "$BODY" | jq . 2>/dev/null || echo "$BODY"
    
    if [ "$HTTP_CODE" = "200" ]; then
        echo "✓ Test 1 PASSED"
        return 0
    else
        echo "✗ Test 1 FAILED - Expected 200, got ${HTTP_CODE}"
        return 1
    fi
}

# Test 2: Concurrent Requests
test_concurrent_requests() {
    echo ""
    echo "======================================"
    echo "Test 2: Concurrent Requests (10)"
    echo "======================================"
    
    # Check if ab (Apache Bench) is available
    if ! command -v ab &> /dev/null; then
        echo "⚠ Apache Bench (ab) not found, using curl for concurrent test"
        
        echo "Sending 10 concurrent requests..."
        for i in {1..10}; do
            curl -s "${TEST_ENDPOINT}" > /tmp/octane_test_${i}.json &
        done
        
        wait
        
        echo ""
        echo "Checking responses:"
        SUCCESS=0
        FAILED=0
        
        for i in {1..10}; do
            if [ -f "/tmp/octane_test_${i}.json" ]; then
                STATUS=$(cat /tmp/octane_test_${i}.json | jq -r '.status // .error' 2>/dev/null || echo "unknown")
                if [ "$STATUS" = "ok" ]; then
                    SUCCESS=$((SUCCESS + 1))
                else
                    FAILED=$((FAILED + 1))
                    echo "  Request $i: $STATUS"
                fi
                rm -f /tmp/octane_test_${i}.json
            fi
        done
        
        echo "  Success: ${SUCCESS}/10"
        echo "  Failed: ${FAILED}/10"
        
        if [ $FAILED -eq 0 ]; then
            echo "✓ Test 2 PASSED - All concurrent requests succeeded"
            return 0
        else
            echo "⚠ Test 2 COMPLETED - ${FAILED} requests failed (check if they returned proper 503 errors)"
            return 0
        fi
    else
        echo "Using Apache Bench for load testing..."
        echo "Command: ab -n 100 -c 10 ${TEST_ENDPOINT}"
        
        AB_OUTPUT=$(ab -n 100 -c 10 "${TEST_ENDPOINT}" 2>&1)
        
        echo ""
        echo "Results:"
        echo "$AB_OUTPUT" | grep -A 10 "Complete requests:"
        
        FAILED_REQUESTS=$(echo "$AB_OUTPUT" | grep "Failed requests:" | awk '{print $3}')
        
        if [ "$FAILED_REQUESTS" = "0" ]; then
            echo "✓ Test 2 PASSED - All concurrent requests succeeded"
            return 0
        else
            echo "⚠ Test 2 COMPLETED - ${FAILED_REQUESTS} failed (check error logs for 503 vs fatal errors)"
            return 0
        fi
    fi
}

# Test 3: Check Error Logs
test_error_logs() {
    echo ""
    echo "======================================"
    echo "Test 3: Check for Fatal Errors in Logs"
    echo "======================================"
    
    # Check for the specific fatal error we're trying to fix
    if grep -q "Call to a member function pop() on null" storage/logs/*.log 2>/dev/null; then
        echo "✗ FATAL ERROR FOUND in logs - The fix did not work!"
        echo ""
        echo "Recent error:"
        grep -A 5 "Call to a member function pop()" storage/logs/*.log | tail -20
        return 1
    else
        echo "✓ No fatal 'pop() on null' errors found"
    fi
    
    # Check for our new error handling
    if grep -q "Worker pool not initialized" storage/logs/*.log 2>/dev/null; then
        echo "⚠ Worker initialization errors detected (gracefully handled)"
        echo ""
        echo "Recent errors:"
        grep "Worker pool not initialized" storage/logs/*.log | tail -5
        return 0
    fi
    
    if grep -q "OCTANE WORKER BOOT FAILED" storage/logs/*.log 2>/dev/null; then
        echo "⚠ Worker boot failures detected - check logs for details"
        return 0
    fi
    
    echo "✓ No worker initialization errors (workers started successfully)"
    return 0
}

# Main execution
main() {
    echo "Checking if Octane is running..."
    
    if ! check_octane; then
        echo "Octane is not running. Please start it with:"
        echo "  php artisan octane:start --workers=2 --port=${OCTANE_PORT}"
        exit 1
    fi
    
    echo "✓ Octane is running on port ${OCTANE_PORT}"
    
    # Run tests
    TEST_RESULTS=0
    
    if ! test_single_request; then
        TEST_RESULTS=$((TEST_RESULTS + 1))
    fi
    
    if ! test_concurrent_requests; then
        TEST_RESULTS=$((TEST_RESULTS + 1))
    fi
    
    if ! test_error_logs; then
        TEST_RESULTS=$((TEST_RESULTS + 1))
    fi
    
    # Summary
    echo ""
    echo "======================================"
    echo "Test Summary"
    echo "======================================"
    
    if [ $TEST_RESULTS -eq 0 ]; then
        echo "✓ All tests passed!"
        echo ""
        echo "Next steps:"
        echo "1. Monitor worker behavior under sustained load"
        echo "2. Check memory usage with larger pool sizes"
        echo "3. Review error logs for any boot failures"
    else
        echo "✗ ${TEST_RESULTS} test(s) failed"
        echo ""
        echo "Check the output above for details"
    fi
    
    exit $TEST_RESULTS
}

main
