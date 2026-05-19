---
name: playwright-automation
description: Automate browsers, test web applications, scrape websites, and perform E2E testing using Playwright MCP. Use when working with web browsers, testing web apps, capturing screenshots, or automating web interactions.
allowed-tools: mcp__playwright__puppeteer_navigate, mcp__playwright__puppeteer_screenshot, mcp__playwright__puppeteer_click, mcp__playwright__puppeteer_fill, mcp__playwright__puppeteer_select, mcp__playwright__puppeteer_hover, mcp__playwright__puppeteer_evaluate, Read, Write, Bash, Grep, Glob
---

# Playwright Browser Automation Skill

This skill enables browser automation and web testing using the Playwright MCP server. It provides accessibility-based automation without requiring screenshots or visual AI models.

## When to Use This Skill

Activate this skill when you need to:
- Test web applications (E2E, integration tests)
- Automate browser interactions and user flows
- Scrape or extract data from websites
- Capture screenshots of web pages or components
- Validate web application functionality
- Fill forms and interact with web elements
- Execute JavaScript in browser context
- Monitor web page behavior

## Core Capabilities

### 1. Browser Navigation
Navigate to URLs and manage browser sessions efficiently.

```javascript
// Navigate to a website
mcp__playwright__puppeteer_navigate({ url: "https://example.com" })
```

### 2. Element Interaction
Click, fill, select, and hover over web elements using CSS selectors.

```javascript
// Click a button
mcp__playwright__puppeteer_click({ selector: "button#submit" })

// Fill an input field
mcp__playwright__puppeteer_fill({ selector: "input[name='email']", value: "user@example.com" })

// Select dropdown value
mcp__playwright__puppeteer_select({ selector: "select#country", value: "US" })

// Hover over element
mcp__playwright__puppeteer_hover({ selector: ".tooltip-trigger" })
```

### 3. Screenshot Capture
Take full-page or element-specific screenshots.

```javascript
// Full page screenshot
mcp__playwright__puppeteer_screenshot({ name: "homepage", width: 1920, height: 1080 })

// Element screenshot
mcp__playwright__puppeteer_screenshot({
  name: "login-form",
  selector: "#login-form"
})
```

### 4. JavaScript Execution
Execute JavaScript code in the browser context.

```javascript
// Extract data with JavaScript
mcp__playwright__puppeteer_evaluate({
  script: "document.querySelectorAll('.product').length"
})
```

## Workflow Examples

### Example 1: E2E Login Test

```markdown
1. Navigate to login page
2. Fill username and password fields
3. Click submit button
4. Take screenshot of dashboard
5. Verify successful login
```

**Implementation:**
```javascript
// Step 1: Navigate
mcp__playwright__puppeteer_navigate({ url: "https://app.example.com/login" })

// Step 2: Fill credentials
mcp__playwright__puppeteer_fill({ selector: "input#username", value: "testuser" })
mcp__playwright__puppeteer_fill({ selector: "input#password", value: "password123" })

// Step 3: Submit
mcp__playwright__puppeteer_click({ selector: "button[type='submit']" })

// Step 4: Capture evidence
mcp__playwright__puppeteer_screenshot({ name: "dashboard-logged-in" })

// Step 5: Verify
mcp__playwright__puppeteer_evaluate({
  script: "document.querySelector('.user-profile') !== null"
})
```

### Example 2: Web Scraping

```markdown
1. Navigate to target website
2. Wait for content to load (auto-handled by Playwright)
3. Execute JavaScript to extract data
4. Parse and structure the data
5. Save to file
```

**Implementation:**
```javascript
// Navigate to site
mcp__playwright__puppeteer_navigate({ url: "https://example.com/products" })

// Extract product data
mcp__playwright__puppeteer_evaluate({
  script: `
    Array.from(document.querySelectorAll('.product')).map(p => ({
      name: p.querySelector('.name').textContent,
      price: p.querySelector('.price').textContent,
      image: p.querySelector('img').src
    }))
  `
})

// Then use Write tool to save the extracted data
```

### Example 3: Form Automation

```markdown
1. Navigate to form page
2. Fill all required fields
3. Upload file (if needed)
4. Submit form
5. Verify success message
6. Take screenshot for record
```

## Best Practices

### 1. Selector Strategy
- **Prefer semantic selectors**: Use `button[aria-label="Submit"]` over `.btn-primary`
- **Use data attributes**: `[data-testid="login-button"]` for stable selectors
- **Avoid fragile selectors**: Don't use `nth-child` or deep CSS paths

### 2. Wait for Stability
- Playwright auto-waits for elements to be ready
- Trust the built-in waiting mechanisms
- Only add explicit waits for unusual cases

### 3. Error Handling
- Always verify elements exist before interaction
- Provide clear error messages with context
- Take screenshots on failures for debugging

### 4. Performance Optimization
- Reuse browser sessions when running multiple tests
- Navigate only when necessary
- Use efficient JavaScript for data extraction

## Troubleshooting

### Common Issues

**Issue: Selector not found**
```
Solution:
1. Verify the element exists on the page
2. Check if page has fully loaded
3. Try alternative selectors (id, class, aria-label)
4. Use mcp__playwright__puppeteer_evaluate to inspect DOM
```

**Issue: Form submission fails**
```
Solution:
1. Ensure all required fields are filled
2. Check for JavaScript validation errors
3. Verify submit button is enabled
4. Try evaluating form.submit() directly
```

**Issue: Screenshot shows blank page**
```
Solution:
1. Add slight delay after navigation
2. Wait for specific element to appear
3. Check if page requires JavaScript
4. Verify network connectivity
```

## Integration with Other MCP Servers

### With Chrome DevTools MCP
Use Chrome DevTools for advanced debugging while Playwright handles automation:
- Playwright: Performs automated actions
- Chrome DevTools: Inspects network, console, performance

### With Puppeteer MCP
Both can complement each other:
- Playwright: Modern, accessibility-focused automation
- Puppeteer: Additional browser control capabilities

### With IDE MCP
Combine with IDE diagnostics:
- Playwright: Tests web application
- IDE MCP: Provides code diagnostics and suggestions

## Advanced Techniques

### 1. Multi-step Workflows
Chain multiple actions in sequence:
```
Navigate → Fill Form → Submit → Wait → Verify → Screenshot → Report
```

### 2. Data-Driven Testing
Loop through test data arrays:
```javascript
// For each test case:
for (const testCase of testData) {
  navigate → fill(testCase) → submit → verify
}
```

### 3. Visual Regression Testing
Compare screenshots over time:
```
1. Take baseline screenshot
2. Make UI changes
3. Take new screenshot
4. Compare visually (manual or automated)
```

### 4. Accessibility Testing
Verify ARIA attributes and semantic HTML:
```javascript
mcp__playwright__puppeteer_evaluate({
  script: `
    const issues = [];
    document.querySelectorAll('button').forEach(btn => {
      if (!btn.hasAttribute('aria-label') && !btn.textContent.trim()) {
        issues.push('Button without label: ' + btn.outerHTML);
      }
    });
    issues;
  `
})
```

## Resources

### Playwright Documentation
- MCP Server: https://github.com/microsoft/playwright-mcp
- Playwright Docs: https://playwright.dev
- CSS Selectors: https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_Selectors

### Testing Strategies
- E2E Testing Best Practices
- Page Object Model patterns
- Test data management
- CI/CD integration

## Example Use Cases

This skill should be automatically activated when users say:
- "Test the login flow on myapp.com"
- "Take a screenshot of the homepage"
- "Automate user registration process"
- "Scrape product listings from ecommerce site"
- "Fill out the contact form and submit"
- "Click through the checkout process"
- "Verify the search functionality works"
- "Capture screenshots of all pages"

## Summary

The Playwright Automation skill provides powerful browser automation capabilities through the Playwright MCP server. It excels at E2E testing, web scraping, form automation, and screenshot capture—all using accessibility-based selectors for robust, maintainable automation scripts.

**Key Strengths:**
- Accessibility-first approach (no visual AI needed)
- Auto-waiting for element stability
- Modern, fast browser automation
- Comprehensive interaction capabilities
- Screenshot and visual documentation
- JavaScript execution in browser context

Use this skill whenever you need to interact with web browsers programmatically!
