# MCP Server Configuration

This document explains how to configure Model Context Protocol (MCP) servers to enable AI access to various capabilities including logs, files, version control, web content, and persistent memory.

## Overview

This project uses **8 MCP servers** (1 custom + 4 npm + 3 Python) to enhance AI capabilities:

| Server | Purpose | Platform | Status |
|--------|---------|----------|--------|
| **local-logs** | Real-time log monitoring and analysis | Node.js | ✅ Active |
| **memory** | Persistent knowledge graph across sessions | npm | ✅ Active |
| **puppeteer** | Browser automation and web scraping | npm | ✅ Active |
| **filesystem** | Secure file operations | npm | ✅ Active |
| **sequential-thinking** | Dynamic problem-solving with structured thinking | npm | ✅ Active |
| **git** | Version control integration | Python | ✅ Active |
| **fetch** | Web content fetching and conversion | Python | ✅ Active |
| **time** | Time and timezone conversions | Python | ✅ Active |

**All servers are installed and configured!** Python servers are installed in `~/.local/bin/`.

## Quick Start

All MCP servers are configured in `.mcp.json` at the project root. After modifying this file, **restart Claude Code** to activate changes.

## Complete Configuration

The project's `.mcp.json` contains:

```json
{
  "mcpServers": {
    "local-logs": {
      "command": "node",
      "args": ["/tmp/local-logs-mcp-server/local-logs-mcp-server.js"],
      "env": {
        "LOGS_DIR": "${PWD}/writable/logs",
        "LOG_EXTENSIONS": ".log,.json,.txt"
      }
    },
    "memory": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-memory"]
    },
    "puppeteer": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-puppeteer"]
    },
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "${PWD}"]
    },
    "sequential-thinking": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-sequential-thinking"]
    },
    "git": {
      "command": "/home/gabriel/.local/bin/mcp-server-git",
      "args": ["--repository", "${PWD}"]
    },
    "fetch": {
      "command": "/home/gabriel/.local/bin/mcp-server-fetch",
      "args": []
    },
    "time": {
      "command": "/home/gabriel/.local/bin/mcp-server-time",
      "args": []
    }
  }
}
```

---

## 1. Local Logs MCP Server (Custom)

**Purpose:** Real-time log monitoring and analysis for CQRS application logs

**Capabilities:**
- 📜 Read logs in real-time - Get the last N lines from any log file
- 🔍 Search and filter logs - Find specific errors or events by pattern
- ⚠️ Monitor errors - Quickly identify issues in error logs
- 📊 Analyze patterns - Understand application behavior from JSON logs
- 🔗 Correlation ID tracking - Trace requests across handlers

**Installation (Custom Server):**

The Local Logs MCP Server has been installed locally in this project at:
```
/tmp/local-logs-mcp-server/
```

**Alternative installation:**
```bash
# Clone from GitHub
git clone https://github.com/mariosss/local-logs-mcp-server.git
cd local-logs-mcp-server
npm install
```

## Configuration

### For Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "local-logs": {
      "command": "node",
      "args": ["/tmp/local-logs-mcp-server/local-logs-mcp-server.js"],
      "env": {
        "LOGS_DIR": "/home/gabriel/Documentos/CQRSTemplate/writable/logs",
        "LOG_EXTENSIONS": ".log,.json,.txt"
      }
    }
  }
}
```

### For Claude Code

**Project-scoped configuration (recommended):**

Add to `.mcp.json` in your project root:

```json
{
  "mcpServers": {
    "local-logs": {
      "command": "node",
      "args": ["/tmp/local-logs-mcp-server/local-logs-mcp-server.js"],
      "env": {
        "LOGS_DIR": "${PWD}/writable/logs",
        "LOG_EXTENSIONS": ".log,.json,.txt"
      }
    }
  }
}
```

✅ **This file has been created at the project root** and can be checked into version control.

### For Cursor or Other Editors

Add to `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "local-logs": {
      "command": "node",
      "args": ["/tmp/local-logs-mcp-server/local-logs-mcp-server.js"],
      "env": {
        "LOGS_DIR": "./writable/logs",
        "LOG_EXTENSIONS": ".log,.json,.txt"
      }
    }
  }
}
```

### Configuration Options (Local Logs Specific)

| Variable | Description | Default | Example |
|----------|-------------|---------|---------|
| `LOGS_DIR` | Directory containing log files | `./logs` | `/path/to/writable/logs` |
| `LOG_EXTENSIONS` | File extensions to monitor | `.log` | `.log,.json,.txt` |

### Log File Locations

This project writes logs to:
- **Application logs**: `writable/logs/app-YYYY-MM-DD.json`
- **Error logs**: `writable/logs/app-YYYY-MM-DD.json` (with level=error)
- **Domain logs**: Organized by channel (e.g., `cookie.command.create`)

All logs are in **JSON format** for easy AI parsing.

### Usage Examples

Once configured, you can ask AI assistants:

**Basic Log Reading:**
```
"Show me the last 50 lines from today's application log"
"What errors occurred in the last hour?"
"Tail the logs and show me what's happening"
```

**Filtering and Analysis:**
```
"Find all ERROR level logs from the Cookie domain"
"Show me logs related to CreateCookieCommand"
"What happened around 14:30 today?"
```

**Pattern Detection:**
```
"Are there any repeated errors in the logs?"
"What's the most common error today?"
"Show me the error distribution by domain"
```

**CQRS-Specific Queries:**
```
"Show logs for all Create commands"
"Find failed query executions"
"What events were dispatched in the last 10 minutes?"
```

### JSON Log Format

Each log entry contains:

```json
{
  "timestamp": "2025-10-22T10:30:00+00:00",
  "level": "INFO",
  "channel": "cookie.command.create",
  "message": "Creating cookie",
  "context": {
    "domain": "Cookie",
    "command": "CreateCookie",
    "cookieName": "Chocolate Chip",
    "userId": 123
  },
  "extra": {
    "memory_usage": "2MB",
    "execution_time": "0.05s"
  }
}
```

---

## 2. Memory MCP Server (Official Anthropic)

**Purpose:** Persistent knowledge graph that remembers information about users, projects, and preferences across sessions

**Package:** `@modelcontextprotocol/server-memory`

**Capabilities:**
- 🧠 **Knowledge Graph Storage** - Stores entities, relations, and observations
- 🔗 **Entity Management** - Create and manage entities (people, organizations, events)
- ↔️ **Relation Tracking** - Define relationships between entities
- 📝 **Observation Recording** - Attach discrete facts to entities
- 🔄 **Cross-Session Memory** - Remember context across multiple AI conversations

**How It Works:**

The memory server organizes information using three concepts:

1. **Entities** - Primary nodes with name, type, and observations
   - Example: `{"name": "Cookie Domain", "entityType": "module", "observations": ["Uses CQRS pattern", "Has 45 files"]}`

2. **Relations** - Directed connections between entities
   - Example: `"Cookie Domain" --[implements]--> "CQRS Pattern"`

3. **Observations** - Individual facts about entities
   - Example: `"Has logging infrastructure"`, `"Uses Monolog library"`

**Use Cases for This Project:**
```
"Remember that the Cookie domain is the reference implementation"
"Note that this project uses PHPStan Level 8"
"Record that Gabriel prefers comprehensive documentation"
"What architectural patterns does this project use?"
"Show me what you remember about CQRS implementation"
```

**Installation:**
Already configured in `.mcp.json`. Restart Claude Code to activate.

---

## 3. Puppeteer MCP Server (Official Anthropic)

**Purpose:** Browser automation and web scraping capabilities

**Package:** `@modelcontextprotocol/server-puppeteer`

**Capabilities:**
- 🌐 **Browser Control** - Launch and control headless Chrome browser
- 📸 **Screenshots** - Capture webpage screenshots for analysis
- 📄 **Page Scraping** - Extract content from web pages
- 🖱️ **Interaction** - Click buttons, fill forms, navigate pages
- 🧪 **Testing** - Automated testing of web applications

**Use Cases for This Project:**
```
"Test the cookie list page UI"
"Take a screenshot of the create cookie form"
"Verify the pagination works on the cookies table"
"Check if error messages display correctly"
"Test the responsive design of the application"
```

**Installation:**
Already configured in `.mcp.json`. Restart Claude Code to activate.

---

## 4. Filesystem MCP Server (Official Anthropic)

**Purpose:** Secure file operations with configurable access controls

**Package:** `@modelcontextprotocol/server-filesystem`

**Capabilities:**
- 📁 **Directory Navigation** - List and explore directory structures
- 📖 **File Reading** - Read file contents with permission controls
- ✍️ **File Writing** - Create and modify files (if configured)
- 🔍 **Search** - Find files by pattern or content
- 🛡️ **Access Control** - Restricted to configured directory (`${PWD}`)

**Use Cases for This Project:**
```
"List all PHP files in the Cookie domain"
"Show me the structure of the Domain directory"
"Find all files that implement CommandHandlerInterface"
"Search for uses of LoggerInterface"
```

**Security:**
- Scoped to project directory only (`${PWD}`)
- No access to parent directories or system files
- Safe for use in development environments

**Installation:**
Already configured in `.mcp.json`. Restart Claude Code to activate.

---

## 5. Sequential Thinking MCP Server (Official Anthropic)

**Purpose:** Dynamic and reflective problem-solving through structured thinking process

**Package:** `@modelcontextprotocol/server-sequential-thinking`

**Capabilities:**
- 🧠 **Structured Thinking** - Step-by-step problem-solving process
- 🔄 **Reflective Analysis** - Review and refine thought processes
- 📊 **Complex Problem Decomposition** - Break down multi-step challenges
- 💭 **Transparent Reasoning** - Show AI's thinking process explicitly
- 🎯 **Decision Making** - Evaluate multiple approaches systematically

**Use Cases for This Project:**
```
"Help me plan a complex refactoring of the Cookie domain"
"Analyze the trade-offs between different CQRS patterns"
"Design a new domain with comprehensive requirements analysis"
"Evaluate architectural decisions for event sourcing"
"Debug a complex multi-handler workflow issue"
```

**Installation:**
Already configured in `.mcp.json`. Restart Claude Code to activate.

---

---

## 6. Git MCP Server (Official Anthropic - Python)

**Purpose:** Version control integration for git repositories

**Package:** `mcp-server-git` (PyPI) - ✅ **Installed at** `~/.local/bin/`

**Capabilities:**
- 📊 **Repository Status** - Check current branch, changes, and history
- 📜 **Commit History** - View commit logs and diffs
- 🔍 **File History** - Track changes to specific files over time
- 🌿 **Branch Management** - List and inspect branches
- 🔎 **Blame Analysis** - Identify who changed specific lines

**Use Cases for This Project:**
```
"Show me recent commits to the Cookie domain"
"Who last modified the CreateCookieHandler?"
"What files changed in the logging implementation?"
"Show the commit history for CookieRepository"
"List all branches in this repository"
```

**Installation:**
```bash
pip3 install --user --break-system-packages mcp-server-git
```

---

## 7. Fetch MCP Server (Official Anthropic - Python)

**Purpose:** Web content fetching and conversion for efficient LLM processing

**Package:** `mcp-server-fetch` (PyPI) - ✅ **Installed at** `~/.local/bin/`

**Capabilities:**
- 🌐 **HTTP Requests** - Fetch content from any URL
- 📝 **Content Conversion** - Convert HTML to markdown for easier parsing
- 📡 **API Calls** - Access REST APIs and web services
- 📚 **Documentation Reading** - Fetch and parse online documentation
- 🔍 **Research** - Gather information from web sources
- 🤖 **Robots.txt Compliance** - Respects website crawling policies

**Use Cases for This Project:**
```
"Fetch the latest Monolog documentation"
"Check the CodeIgniter 4 migration guide"
"Read the PSR-3 logging specification"
"Get information about PHPStan Level 8 rules"
"Research CQRS best practices"
```

**Installation:**
```bash
pip3 install --user --break-system-packages mcp-server-fetch
```

---

## 8. Time MCP Server (Official Anthropic - Python)

**Purpose:** Time and timezone conversion capabilities

**Package:** `mcp-server-time` (PyPI) - ✅ **Installed at** `~/.local/bin/`

**Capabilities:**
- 🕐 **Current Time** - Get current time in any timezone
- 🔄 **Timezone Conversion** - Convert times between timezones
- 📅 **Date Calculations** - Calculate date differences and durations
- ⏱️ **Timestamp Formatting** - Format timestamps in various formats
- 🌍 **Timezone Info** - Get timezone information and offsets

**Use Cases for This Project:**
```
"What time is it in UTC?"
"Convert log timestamp to America/Sao_Paulo timezone"
"Calculate duration between two timestamps in logs"
"Format current time for PHP DateTime"
"Show timezone offset for correlation ID timestamps"
```

**Installation:**
```bash
pip3 install --user --break-system-packages mcp-server-time
```

---

## Installing Additional Python MCP Servers

To install more Python-based MCP servers:

```bash
# Install individual servers
pip3 install --user --break-system-packages <package-name>

# Or use the installation script
bash temp/install-python-mcp-servers.sh
```

**Note:** Python servers are installed to `~/.local/bin/`. If they're not in your PATH, use the full path in `.mcp.json` configuration (as shown above).

---

## Troubleshooting (Local Logs Server)

### MCP Server Not Connecting

1. **Check if server is installed:**
   ```bash
   test -d /tmp/local-logs-mcp-server && echo "Installed" || echo "Not found"
   ```

2. **Verify Node.js is available:**
   ```bash
   node --version
   ```

3. **Check logs directory exists:**
   ```bash
   ls -la writable/logs/
   ```

4. **Restart Claude Desktop/Code** after config changes

### AI Can't Read Logs

1. **Verify log files exist:**
   ```bash
   ls writable/logs/*.json
   ```

2. **Check JSON format:**
   ```bash
   head -1 writable/logs/app-$(date +%Y-%m-%d).json | python3 -m json.tool
   ```

3. **Check file permissions:**
   ```bash
   ls -l writable/logs/
   ```

### No Logs Being Created

1. **Trigger logging** by creating a cookie:
   ```bash
   php spark app:create-cookie "Test Cookie" 5.99 "Chocolate"
   ```

2. **Check LoggerFactory is registered** in service provider

3. **Verify handlers inject LoggerInterface**

## MCP Server Features

### Available Tools

The Local Logs MCP Server provides these tools:

- `listLogFiles` - List all available log files
- `readLogFile` - Read last N lines from a log file
- `searchLogs` - Search for specific text across logs
- `getErrorSummary` - Get summary of error logs
- `tailLogs` - Real-time log tailing

### Log File Discovery

The server automatically discovers all files matching:
- `*.log` files
- `*.json` files
- `*.txt` files

in the configured `LOGS_DIR`.

## Framework-Agnostic Design

This logging setup is **completely framework-agnostic**:

- ✅ **Monolog** - Works with any PHP framework
- ✅ **LoggerFactory** - Pure PHP, no CI4 dependencies
- ✅ **MCP Server** - Language/framework independent
- ✅ **JSON Logs** - Universal format

If you remove CodeIgniter 4, the logging infrastructure continues to work unchanged!

## Security Considerations

**Important:**
- MCP server runs **locally only**
- No network exposure
- Logs should **never contain sensitive data** (passwords, tokens, PII)
- Use context processors to sanitize sensitive fields
- Configure `.gitignore` to exclude log files from version control

## Next Steps

1. **Restart Claude Desktop/Code** after configuration
2. **Create test logs** by using the application
3. **Ask AI to read logs** to verify MCP server works
4. **Review** `.claude/CLAUDE.md` for logging usage in handlers

---

## General MCP Server Troubleshooting

### MCP Servers Not Detected

**Problem:** Running `/mcp` shows "No MCP servers configured"

**Solutions:**
1. **Verify `.mcp.json` exists at project root:**
   ```bash
   ls -la .mcp.json
   ```

2. **Check JSON syntax is valid:**
   ```bash
   cat .mcp.json | jq .
   ```

3. **Restart Claude Code** - MCP configuration is loaded at startup

4. **Check Claude Code output** for MCP server connection errors

### Individual Server Not Working

**Problem:** One MCP server fails while others work

**Solutions:**
1. **Check npx is available:**
   ```bash
   npx --version
   ```

2. **Test server installation manually:**
   ```bash
   npx -y @modelcontextprotocol/server-memory
   ```

3. **Check server-specific requirements:**
   - **Memory**: Requires writable directory for knowledge graph storage
   - **Puppeteer**: Requires Chrome/Chromium browser installed
   - **Git**: Requires valid git repository at `${PWD}`

4. **Review Claude Code logs** for specific error messages

### Performance Issues

**Problem:** MCP servers slow down Claude Code

**Solutions:**
1. **Disable unused servers** - Comment out in `.mcp.json`
2. **Limit filesystem scope** - Restrict to specific subdirectories
3. **Reduce log file sizes** - Configure log rotation

---

## Complete List of Official Anthropic MCP Servers

### Available on npm (TypeScript/JavaScript)

| Server | Package | Configured | Purpose |
|--------|---------|------------|---------|
| **Filesystem** | `@modelcontextprotocol/server-filesystem` | ✅ | Secure file operations |
| **Memory** | `@modelcontextprotocol/server-memory` | ✅ | Knowledge graph-based persistent memory |
| **Puppeteer** | `@modelcontextprotocol/server-puppeteer` | ✅ | Browser automation |
| **Sequential Thinking** | `@modelcontextprotocol/server-sequential-thinking` | ✅ | Dynamic problem-solving |
| **Everything** | `@modelcontextprotocol/server-everything` | ❌ | Reference/test server |

### Available on PyPI (Python)

| Server | Package | Installed | Purpose |
|--------|---------|-----------|---------|
| **Fetch** | `mcp-server-fetch` | ✅ | Web content fetching and conversion |
| **Git** | `mcp-server-git` | ✅ | Git repository tools |
| **Time** | `mcp-server-time` | ✅ | Time and timezone conversions |

**Note:** All Python servers are installed in `~/.local/bin/` and configured in `.mcp.json`.

### Archived Servers (Moved to separate repository)

These servers were previously official but moved to `servers-archived`:

- **AWS** - AWS service integration
- **GitHub** - GitHub API integration
- **GitLab** - GitLab API integration
- **Google Drive** - Google Drive access
- **PostgreSQL** - PostgreSQL database queries
- **Redis** - Redis key-value store
- **Slack** - Slack workspace integration
- **SQLite** - SQLite database queries

**Note:** For production database access, consider using dedicated MCP servers from the community.

---

## Recommendations for This Project

### Currently Configured (8 servers) - ALL ACTIVE ✅

All 8 servers provide value for this CQRS/DDD/CI4 project:

1. ✅ **local-logs** (Node.js) - Essential for debugging CQRS flows and analyzing JSON logs
2. ✅ **memory** (npm) - Remember architectural decisions and patterns across sessions
3. ✅ **puppeteer** (npm) - Test web UI and forms
4. ✅ **filesystem** (npm) - Navigate domain structure and find files
5. ✅ **sequential-thinking** (npm) - Complex architectural decisions and refactoring planning
6. ✅ **git** (Python) - Track changes to handlers and repositories
7. ✅ **fetch** (Python) - Research PHP/CI4/CQRS documentation
8. ✅ **time** (Python) - Handle timezone conversions in logs

### Optional Additional Servers

**Community Database Servers:**
- **PostgreSQL/MySQL MCP servers** - Direct database schema inspection
- **Search:** https://github.com/wong2/awesome-mcp-servers

**Everything Server:**
- **Use case:** Testing and development only
- **Not recommended** for production workflows

### Not Recommended for This Project

- **AWS/Slack/GitHub** - Not applicable to local development
- **Google Drive** - Not part of this project's workflow
- **Redis/SQLite** - Use native PHP database tools instead

---

## Security Best Practices

### General Security

1. **Scope MCP servers appropriately:**
   - Filesystem: Restrict to `${PWD}` only
   - Git: Limit to project repository
   - Memory: Understand data persists across sessions

2. **Never expose sensitive data:**
   - No passwords in logs (use DomainLogger sanitization)
   - No API keys in git history
   - No PII in memory knowledge graph

3. **Review MCP server permissions:**
   - Understand what each server can access
   - Disable unused servers
   - Monitor Claude Code logs for unexpected behavior

### Project-Specific

1. **Log Sanitization:**
   - Cookie domain already implements error code abstraction
   - Use CorrelationIdService for tracing, not user IDs
   - Validate log entries before committing test files

2. **Version Control:**
   - `.mcp.json` is safe to commit (no secrets)
   - `writable/logs/` is gitignored
   - Memory knowledge graph stored locally (not in repo)

---

## References

### Official Documentation

- **Model Context Protocol**: https://modelcontextprotocol.io
- **Official Servers Repository**: https://github.com/modelcontextprotocol/servers
- **MCP Introduction Course**: https://anthropic.skilljar.com/introduction-to-model-context-protocol

### Community Resources

- **Awesome MCP Servers**: https://github.com/wong2/awesome-mcp-servers
- **MCP Server Finder**: https://www.mcpserverfinder.com

### This Project

- **Local Logs MCP Server**: https://github.com/mariosss/local-logs-mcp-server
- **Monolog Documentation**: https://github.com/Seldaek/monolog
- **PSR-3 Logger Interface**: https://www.php-fig.org/psr/psr-3/
- **CodeIgniter 4**: https://codeigniter.com/user_guide/

### PHP Standards

- **PSR-3 (Logging)**: https://www.php-fig.org/psr/psr-3/
- **PSR-12 (Coding Style)**: https://www.php-fig.org/psr/psr-12/
- **PHPStan**: https://phpstan.org/user-guide/rule-levels
