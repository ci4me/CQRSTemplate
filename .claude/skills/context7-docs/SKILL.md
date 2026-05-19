---
name: context7-docs
description: Retrieve up-to-date, version-specific documentation and code examples from official sources using Context7 MCP. Use when needing current API docs, library guides, framework references, or to prevent AI hallucinations with real documentation.
allowed-tools: Read, Write, Bash, Grep, Glob
---

# Context7 Documentation Skill

This skill provides access to up-to-date, version-specific documentation from official sources through the Context7 MCP server, preventing AI hallucinations and ensuring accurate technical guidance.

## When to Use This Skill

Activate this skill when you need to:
- Look up current API documentation
- Get version-specific code examples
- Verify method signatures and parameters
- Check for deprecated features
- Find migration guides for version upgrades
- Retrieve official integration examples
- Prevent hallucinated or outdated information
- Access framework and library guides
- Confirm feature availability in specific versions
- Get up-to-date best practices

## Core Capabilities

### 1. Up-to-Date Documentation Access
Pull documentation directly from official sources, ensuring information is current and accurate.

**Key Features:**
- Always current (not from training data)
- Version-specific content
- Official maintainer sources
- Real, tested code examples
- Complete API references

### 2. Version-Specific Information
Get documentation matched to exact library versions.

**Benefits:**
- Avoid version mismatch errors
- Find breaking changes
- Locate migration paths
- Check feature availability
- Verify compatibility

### 3. Hallucination Prevention
Verify all suggestions against real documentation before recommending.

**What We Prevent:**
- Non-existent methods/APIs
- Incorrect method signatures
- Deprecated feature recommendations
- Outdated best practices
- Version incompatibilities

### 4. Code Example Retrieval
Access real, working code examples from official documentation.

**Example Types:**
- Quick start guides
- Integration examples
- Common use cases
- Migration snippets
- Configuration templates

## Supported Technologies

Context7 provides documentation for hundreds of libraries and frameworks:

### Frontend Frameworks
- **React**: Hooks, components, lifecycle, Concurrent features
- **Vue**: Composition API, Options API, Vue Router, Pinia
- **Angular**: Components, services, directives, RxJS
- **Svelte**: Components, stores, animations
- **Next.js**: App Router, Pages Router, API routes, SSR/SSG
- **Nuxt**: Composition API, modules, server routes

### Backend Frameworks
- **Node.js**: Core APIs, async patterns, streams
- **Express**: Routing, middleware, error handling
- **Fastify**: Plugins, decorators, hooks
- **NestJS**: Modules, controllers, providers
- **Django**: Models, views, templates, ORM
- **Flask**: Routes, blueprints, extensions

### Databases & ORMs
- **PostgreSQL**: SQL syntax, functions, types
- **MongoDB**: Queries, aggregation, indexes
- **MySQL**: SQL operations, procedures
- **Prisma**: Schema, client queries, migrations
- **Sequelize**: Models, queries, associations
- **Mongoose**: Schemas, models, queries

### Cloud Platforms
- **AWS**: Services, SDKs, CLI
- **Google Cloud**: APIs, SDK, Cloud Functions
- **Azure**: Services, SDK, Functions
- **Vercel**: Deployment, serverless functions
- **Netlify**: Build config, serverless functions

### Testing Frameworks
- **Jest**: Matchers, mocks, setup
- **Vitest**: Config, API, assertions
- **Cypress**: Commands, assertions, API
- **Playwright**: Automation, assertions, fixtures
- **Testing Library**: Queries, events, best practices

### Build Tools
- **Vite**: Config, plugins, build options
- **Webpack**: Config, loaders, plugins
- **ESBuild**: API, config, transforms
- **Rollup**: Config, plugins, output

### TypeScript & JavaScript
- **TypeScript**: Types, generics, utility types
- **JavaScript**: ES2024+ features, APIs, syntax

### And many more...

## Usage Patterns

### Pattern 1: API Reference Lookup

**User Query:**
"How do I make HTTP requests in Node.js 20?"

**Context7 Workflow:**
```
1. Query Context7 for: "Node.js 20 fetch API"
2. Retrieve official Node.js 20 documentation
3. Extract fetch() API signature
4. Get code examples
5. Provide response with:
   - Method signature: fetch(url, options)
   - Available options
   - Real code example
   - Version note (available since Node 18)
   - Link to official docs
```

**Response:**
```markdown
## Node.js 20 - Fetch API

The Fetch API is built into Node.js 20 (available since v18).

### API Signature
javascript
fetch(resource: string | URL, options?: RequestInit): Promise<Response>


### Example Usage
javascript
// Simple GET request
const response = await fetch('https://api.example.com/data');
const data = await response.json();

// POST request with JSON
const response = await fetch('https://api.example.com/users', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({ name: 'John' }),
});


### References
- Version: Node.js 20.x
- Official docs: https://nodejs.org/docs/latest-v20.x/api/globals.html#fetch
```

### Pattern 2: Preventing Hallucinations

**User Query:**
"How do I use the useFetch hook in React?"

**Without Context7:**  ❌
```markdown
You can use React's built-in useFetch hook like this:
[HALLUCINATED CODE - useFetch doesn't exist in React]
```

**With Context7:** ✅
```markdown
## React - useFetch Hook

React doesn't have a built-in `useFetch` hook. Here are the correct approaches:

### Option 1: useEffect + fetch (React 18)
javascript
import { useState, useEffect } from 'react';

function useData(url) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch(url)
      .then(res => res.json())
      .then(data => {
        setData(data);
        setLoading(false);
      });
  }, [url]);

  return { data, loading };
}


### Option 2: Use a library
- **SWR** (by Vercel): import useSWR from 'swr'
- **React Query** (TanStack): import { useQuery } from '@tanstack/react-query'

### References
- React hooks docs: https://react.dev/reference/react
```

### Pattern 3: Version Migration

**User Query:**
"How do I migrate from React 17 to React 18?"

**Context7 Workflow:**
```
1. Query: "React 18 migration guide"
2. Fetch breaking changes from official docs
3. Get upgrade steps
4. Retrieve code migration examples
5. Provide comprehensive migration guide
```

**Response:**
```markdown
## React 17 → React 18 Migration Guide

### Step 1: Update Dependencies
bash
npm install react@18 react-dom@18


### Step 2: Update Root Rendering
javascript
// React 17
import ReactDOM from 'react-dom';
ReactDOM.render(<App />, document.getElementById('root'));

// React 18
import { createRoot } from 'react-dom/client';
const root = createRoot(document.getElementById('root'));
root.render(<App />);


### Step 3: Adopt New Features (Optional)
- **Concurrent Rendering**: Enabled by default
- **Automatic Batching**: Updates batched in all contexts
- **Transitions**: Use startTransition for non-urgent updates
- **Suspense**: Enhanced SSR support

### Breaking Changes
1. ReactDOM.render → createRoot
2. Automatic batching changes (usually improvements)
3. Stricter hydration warnings
4. Internet Explorer no longer supported

### References
- Migration guide: https://react.dev/blog/2022/03/08/react-18-upgrade-guide
- React 18 release: https://react.dev/blog/2022/03/29/react-v18
```

### Pattern 4: Checking Deprecations

**User Query:**
"How do I use componentWillMount in React?"

**Context7 Workflow:**
```
1. Query: "React lifecycle methods"
2. Check method status in current version
3. Identify deprecation
4. Fetch modern alternative
5. Provide migration path
```

**Response:**
```markdown
## React - componentWillMount (⚠️ DEPRECATED)

`componentWillMount` has been **deprecated since React 16.3** (2018).

### ❌ Old Way (Don't use)
javascript
componentWillMount() {
  // This will trigger warnings
}


### ✅ Modern Alternatives

**For class components:**
javascript
constructor(props) {
  super(props);
  // Initialize state here
  this.state = { data: null };
}

componentDidMount() {
  // Side effects here (fetch, subscriptions)
}


**For function components (recommended):**
javascript
import { useState, useEffect } from 'react';

function MyComponent() {
  const [data, setData] = useState(null);

  useEffect(() => {
    // Runs after mount (like componentDidMount)
  }, []);

  return <div>{data}</div>;
}


### Why Deprecated?
- Caused issues with async rendering
- Ran multiple times with Suspense
- Replaced by safer alternatives

### References
- Lifecycle diagram: https://projects.wojtekmaj.pl/react-lifecycle-methods-diagram/
- React docs: https://react.dev/reference/react/Component#unsafe_componentwillmount
```

## Best Practices

### 1. Always Specify Versions
```
BAD:  "How to use TypeScript generics?"
GOOD: "How to use TypeScript 5.3 generics?"

BAD:  "Next.js routing example"
GOOD: "Next.js 14 app router example"
```

### 2. Query Construction
```
Effective queries include:
- Library name + version
- Specific feature or API
- Context (e.g., "with TypeScript", "for testing")

Examples:
✅ "React 18 useTransition hook"
✅ "Next.js 14 app router layouts"
✅ "TypeScript 5.3 satisfies operator"
✅ "Vite 5 proxy configuration"
```

### 3. Verify Before Suggesting
Always check documentation to confirm:
- Method exists in specified version
- Parameters and return types are correct
- Feature is not deprecated
- Code example is accurate
- Best practices are current

### 4. Provide Context
Include in responses:
- Exact version numbers
- Links to official documentation
- Deprecation warnings if applicable
- Alternative approaches
- Migration paths for deprecated features

## Integration Examples

### With Playwright Skill
```markdown
## Scenario: User asks about Playwright API

1. Context7: Fetch Playwright 1.40 API docs
2. Verify: page.click() method signature
3. Confirm: Available options (force, timeout, etc.)
4. Playwright Skill: Execute automation with correct API
5. Result: No errors from hallucinated methods!
```

### With Chrome DevTools Skill
```markdown
## Scenario: User asks about performance optimization

1. Chrome DevTools: Identifies high CLS score
2. Context7: Fetches latest Core Web Vitals best practices
3. Context7: Gets current optimization techniques
4. Provide: Up-to-date solutions (not 2-year-old advice)
```

### With IDE MCP
```markdown
## Scenario: TypeScript error in code

1. IDE MCP: Reports type error
2. Context7: Fetches TypeScript 5.x type system docs
3. Context7: Verifies correct type syntax
4. Provide: Accurate type definitions
```

## Common Use Cases

### Use Case 1: Learning New API
```
User: "I want to use the Next.js 14 app router"

Actions:
1. Query Context7: "Next.js 14 app router"
2. Fetch: Routing conventions, file structure
3. Get: Page, layout, template examples
4. Provide: Complete guide with real examples
```

### Use Case 2: Debugging API Error
```
User: "Getting error: X is not a function in lodash"

Actions:
1. Query Context7: "lodash version X API"
2. Verify: Method exists in that version
3. Check: Method signature and parameters
4. Provide: Correct usage or alternative
```

### Use Case 3: Version Upgrade
```
User: "Upgrading Vue 2 to Vue 3"

Actions:
1. Query Context7: "Vue 3 migration guide"
2. Fetch: Breaking changes list
3. Get: Code migration examples
4. Provide: Step-by-step upgrade path
```

### Use Case 4: Best Practices
```
User: "What's the best way to handle errors in async functions?"

Actions:
1. Query Context7: "JavaScript async error handling"
2. Fetch: Official MDN documentation
3. Get: Current best practices
4. Provide: Modern patterns (try/catch, Promise.catch)
```

## Hallucination Prevention Checklist

Before responding to any technical query:
- [ ] Query Context7 for current documentation
- [ ] Verify method/API exists in specified version
- [ ] Check for deprecations
- [ ] Confirm method signatures
- [ ] Validate code examples
- [ ] Check breaking changes if version-specific
- [ ] Provide links to official sources
- [ ] Note version numbers in response

## Response Quality Standards

Every Context7-enhanced response should include:

1. **Version Specification**
   ```
   React 18.2, Node.js 20.x, TypeScript 5.3
   ```

2. **API Signature**
   ```typescript
   function signature(params: Types): ReturnType
   ```

3. **Real Code Example**
   ```javascript
   // From official documentation
   ```

4. **Status Indicators**
   ```
   ✅ Current (recommended)
   ⚠️  Deprecated (avoid)
   🆕 New in version X
   ```

5. **Official References**
   ```
   https://official-docs.com/api-reference
   ```

## Summary

The Context7 Documentation Skill ensures all technical recommendations are grounded in **real, current, verified documentation** rather than potentially outdated or hallucinated information.

**Key Benefits:**
- 🎯 **100% Accuracy**: Real docs, zero hallucinations
- 📅 **Always Current**: Latest official information
- 🔖 **Version-Matched**: Exact stack compatibility
- 📚 **Official Sources**: From library maintainers
- 🚀 **Tested Examples**: Real, working code
- ⚡ **Prevents Errors**: Catch issues before they happen

**Value Proposition:**
Instead of guessing or relying on training data that might be outdated, Context7 provides **ground truth** directly from official sources, ensuring developers get accurate, current, version-specific guidance every time.

Use this skill whenever you need **verified, up-to-date technical documentation**!
