---
name: chrome-devtools-expert
description: Debug web applications, analyze performance, inspect network requests, and monitor browser console using Chrome DevTools MCP. Use when debugging web apps, optimizing performance, investigating console errors, or analyzing network activity.
allowed-tools: Read, Write, Bash, Grep, Glob
---

# Chrome DevTools Expert Skill

This skill provides advanced Chrome DevTools integration for debugging, performance analysis, network inspection, and console monitoring through the Chrome DevTools MCP server.

## When to Use This Skill

Activate this skill when you need to:
- Debug web application issues
- Analyze page performance and Core Web Vitals
- Inspect network requests and responses
- Monitor browser console errors and warnings
- Profile JavaScript execution
- Optimize page load times
- Investigate layout shifts and rendering issues
- Debug API calls and network failures
- Capture performance traces
- Take diagnostic screenshots

## Core Capabilities

### 1. Performance Profiling
Record and analyze performance traces to identify bottlenecks.

**Key Metrics:**
- **LCP (Largest Contentful Paint)**: Main content load time
- **FID (First Input Delay)**: Interaction responsiveness
- **CLS (Cumulative Layout Shift)**: Visual stability
- **TTFB (Time to First Byte)**: Server response time
- **TBT (Total Blocking Time)**: Main thread blocking

### 2. Network Analysis
Monitor and analyze all network activity.

**Capabilities:**
- Capture HTTP/HTTPS requests
- Analyze request/response headers
- Measure request timing (DNS, TCP, TLS, transfer)
- Identify failed requests (4xx, 5xx errors)
- Inspect payloads and cookies
- Debug CORS and CSP issues

### 3. Console Monitoring
Access browser console output and errors.

**Features:**
- JavaScript errors and exceptions
- Warning and info messages
- Console.log outputs
- Stack traces
- Unhandled promise rejections
- Performance warnings

### 4. Screenshot & Documentation
Capture visual evidence for debugging.

**Use Cases:**
- Document current page state
- Capture errors visually
- Compare before/after changes
- Create bug reports with screenshots

## Workflow Examples

### Example 1: Performance Audit

```markdown
## Performance Audit Workflow

1. Start Chrome DevTools performance recording
2. Navigate to page or trigger user interaction
3. Stop recording after key actions complete
4. Analyze performance trace:
   - Check Core Web Vitals (LCP, FID, CLS)
   - Identify long tasks (>50ms)
   - Find render-blocking resources
   - Measure JavaScript execution time
5. Take screenshot of performance profile
6. Generate optimization report

### Key Checks:
- ✅ LCP < 2.5s
- ✅ FID < 100ms
- ✅ CLS < 0.1
- ⚠️  Long tasks < 50ms
- ⚠️  Main thread idle time > 50%
```

### Example 2: Network Debugging

```markdown
## Network Request Debugging

1. Enable network monitoring
2. Load page or trigger action
3. Capture all network activity
4. Filter and analyze:
   - Failed requests (status 4xx, 5xx)
   - Slow requests (>1 second)
   - Large payloads (>1MB)
   - Redundant requests
5. Inspect request/response details
6. Identify optimization opportunities

### Common Issues:
- Missing compression (gzip/brotli)
- No caching headers
- Unoptimized images
- Too many requests (no bundling)
- Slow API responses
```

### Example 3: Console Error Investigation

```markdown
## Console Error Debugging

1. Navigate to problematic page
2. Capture console output
3. Filter by severity:
   - Errors (red)
   - Warnings (yellow)
   - Info (blue)
4. Analyze error stack traces
5. Identify error patterns
6. Cross-reference with source code
7. Propose fixes

### Analysis Steps:
- Group similar errors
- Identify error frequency
- Check error timing (load vs runtime)
- Verify browser compatibility
- Look for third-party script errors
```

### Example 4: Full Site Audit

```markdown
## Comprehensive Web App Audit

1. **Performance Pass**
   - Record performance trace
   - Measure Core Web Vitals
   - Identify long tasks
   - Check resource loading

2. **Network Pass**
   - Capture all requests
   - Analyze failed requests
   - Check compression & caching
   - Measure request timings

3. **Console Pass**
   - Collect all console output
   - Filter errors vs warnings
   - Analyze error patterns
   - Check for memory leaks

4. **Screenshot Evidence**
   - Capture current state
   - Document issues visually

5. **Generate Report**
   - Executive summary
   - Detailed findings
   - Prioritized recommendations
   - Code examples for fixes
```

## Performance Optimization Patterns

### Optimizing LCP (Largest Contentful Paint)

**Common Causes:**
- Large, unoptimized images
- Render-blocking resources (CSS, JS)
- Slow server response (TTFB)
- Client-side rendering delays

**Solutions:**
```javascript
// 1. Optimize images
<img src="hero.webp" alt="Hero" width="800" height="600" loading="eager" />

// 2. Defer non-critical CSS
<link rel="preload" href="critical.css" as="style" onload="this.rel='stylesheet'" />

// 3. Use resource hints
<link rel="preconnect" href="https://cdn.example.com" />
<link rel="dns-prefetch" href="https://api.example.com" />

// 4. Lazy load below-fold images
<img src="image.jpg" loading="lazy" alt="..." />
```

### Reducing CLS (Cumulative Layout Shift)

**Common Causes:**
- Images without dimensions
- Dynamic content injection
- Web fonts loading
- Ads without reserved space

**Solutions:**
```html
<!-- 1. Set explicit dimensions -->
<img src="image.jpg" width="600" height="400" alt="..." />

<!-- 2. Reserve space for dynamic content -->
<div style="min-height: 200px;"> <!-- Content loads here --> </div>

<!-- 3. Optimize font loading -->
<link rel="preload" href="font.woff2" as="font" crossorigin />
<style>
  @font-face {
    font-family: 'MyFont';
    font-display: swap; /* Prevent invisible text */
    src: url('font.woff2') format('woff2');
  }
</style>

<!-- 4. Reserve ad space -->
<div class="ad-container" style="width: 300px; height: 250px;"></div>
```

### Optimizing FID (First Input Delay)

**Common Causes:**
- Long JavaScript tasks blocking main thread
- Too much JavaScript execution at load time
- Unoptimized third-party scripts

**Solutions:**
```javascript
// 1. Code splitting
import(/* webpackChunkName: "heavy-feature" */ './heavy-feature.js')
  .then(module => module.init());

// 2. Defer non-critical JavaScript
<script src="analytics.js" defer></script>

// 3. Break up long tasks
async function processLargeDataset(data) {
  for (let i = 0; i < data.length; i++) {
    await processItem(data[i]);

    // Yield to browser every 50 items
    if (i % 50 === 0) {
      await new Promise(resolve => setTimeout(resolve, 0));
    }
  }
}

// 4. Use web workers for heavy computation
const worker = new Worker('worker.js');
worker.postMessage({ data: heavyData });
```

## Network Optimization Patterns

### Enable Compression
```nginx
# Nginx configuration
gzip on;
gzip_types text/plain text/css application/json application/javascript;
gzip_min_length 1000;

# Or use Brotli (better compression)
brotli on;
brotli_types text/plain text/css application/json application/javascript;
```

### Implement Caching
```
# Cache-Control headers
Cache-Control: public, max-age=31536000, immutable  # For assets with hash
Cache-Control: no-cache  # For HTML (validate before use)
```

### HTTP/2 & HTTP/3
```
# Enable HTTP/2 (automatic multiplexing)
# Enable HTTP/3 (QUIC protocol)
# Reduces need for domain sharding
# Allows parallel requests over single connection
```

### Resource Bundling
```javascript
// Bundle CSS
import './styles.css';
import './theme.css';

// Bundle JavaScript
import { func1 } from './module1';
import { func2 } from './module2';

// Tree-shake unused code
import { usedFunction } from 'large-library';  // Only imports usedFunction
```

## Console Error Patterns

### Common JavaScript Errors

**1. Uncaught TypeError: Cannot read property 'X' of undefined**
```javascript
// Problem
const value = obj.nested.value;  // obj.nested is undefined

// Solution
const value = obj?.nested?.value;  // Optional chaining
// Or
const value = obj && obj.nested && obj.nested.value;
```

**2. Uncaught ReferenceError: X is not defined**
```javascript
// Problem
console.log(myVariable);  // myVariable not declared

// Solution
let myVariable = 'value';
console.log(myVariable);
```

**3. Uncaught (in promise) Error: ...**
```javascript
// Problem
fetch('/api/data');  // No error handling

// Solution
fetch('/api/data')
  .then(response => response.json())
  .catch(error => console.error('Fetch failed:', error));

// Or with async/await
try {
  const response = await fetch('/api/data');
  const data = await response.json();
} catch (error) {
  console.error('Fetch failed:', error);
}
```

### CORS Errors
```
Access to fetch at 'https://api.example.com' from origin 'https://app.example.com'
has been blocked by CORS policy: No 'Access-Control-Allow-Origin' header is present.

Solution:
- Backend must send: Access-Control-Allow-Origin: https://app.example.com
- Or use a proxy during development
- Or run API on same origin
```

## Integration with Other Skills

### With Playwright/Puppeteer
1. Use Playwright to navigate and interact
2. Use Chrome DevTools to monitor performance
3. Combine for comprehensive testing

**Example:**
```
Playwright: Navigate to page, click button
Chrome DevTools: Record performance trace during interaction
Result: Performance profile of user action
```

### With Context7
1. Use Chrome DevTools to identify issues
2. Use Context7 to look up latest solutions
3. Apply best practices from up-to-date docs

**Example:**
```
Chrome DevTools: Identifies high CLS score
Context7: Fetches latest CLS optimization techniques
Result: Up-to-date fix implementation
```

### With IDE MCP
1. Chrome DevTools finds console errors
2. IDE MCP provides code diagnostics
3. Cross-reference errors with code

## Diagnostic Checklist

### Performance Issues
- [ ] Measure Core Web Vitals (LCP, FID, CLS)
- [ ] Identify long tasks (>50ms)
- [ ] Check render-blocking resources
- [ ] Analyze main thread activity
- [ ] Review JavaScript execution time
- [ ] Inspect image optimization
- [ ] Verify font loading strategy

### Network Issues
- [ ] Check failed requests (4xx, 5xx)
- [ ] Analyze slow requests (>1s)
- [ ] Verify compression enabled
- [ ] Review caching strategy
- [ ] Inspect request headers
- [ ] Check for redundant requests
- [ ] Measure TTFB

### Console Issues
- [ ] Filter JavaScript errors
- [ ] Check warning messages
- [ ] Review unhandled promises
- [ ] Analyze error stack traces
- [ ] Verify no CORS errors
- [ ] Check for CSP violations

## Reporting Template

```markdown
# Chrome DevTools Analysis Report

## Executive Summary
[High-level overview of findings]

## Metrics
### Core Web Vitals
- LCP: X.Xs (Good/Needs Improvement/Poor)
- FID: XXms (Good/Needs Improvement/Poor)
- CLS: X.XX (Good/Needs Improvement/Poor)

### Additional Metrics
- TTFB: XXXms
- FCP: X.Xs
- TTI: X.Xs
- TBT: XXXms

## Issues Found (Prioritized)

### High Priority
1. [Issue name] - [Impact]
   - Root cause: [Explanation]
   - Recommendation: [Fix]
   - Code example: [If applicable]

### Medium Priority
2. [Issue name] - [Impact]
   ...

### Low Priority
3. [Issue name] - [Impact]
   ...

## Network Analysis
- Total requests: XX
- Failed requests: X
- Largest resource: X.XMB
- Slowest request: Xs
- Optimization opportunities: [List]

## Console Errors
- Total errors: X
- Error types: [Categories]
- Most common: [Error message]
- Recommendations: [Fixes]

## Recommendations Summary
1. [Top recommendation]
2. [Second recommendation]
3. [Third recommendation]

## Resources
- [Link to relevant documentation]
- [Link to tools or libraries]
```

## Summary

The Chrome DevTools Expert skill provides comprehensive browser debugging and performance analysis capabilities. It excels at identifying performance bottlenecks, analyzing network activity, debugging console errors, and providing actionable optimization recommendations.

**Key Strengths:**
- Deep performance insights (Core Web Vitals)
- Network request analysis and debugging
- Console error monitoring and resolution
- Screenshot capture for documentation
- Integration with browser automation tools
- Actionable, code-level recommendations

Use this skill whenever you need to debug, optimize, or analyze web applications at the browser level!
