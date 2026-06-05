#!/usr/bin/env node
/**
 * Local Logs MCP Server (stdio, zero dependencies)
 *
 * Exposes the application log directory (writable/logs) to MCP clients.
 * Rebuilt in-repo so it survives reboots — the original lived in /tmp and
 * was lost. Documented in .claude/documentation/MCP_SERVER_CONFIG.md.
 *
 * Env:
 *   LOGS_DIR        directory to serve (default: ./writable/logs)
 *   LOG_EXTENSIONS  comma-separated extensions (default: .log,.json,.txt)
 */

'use strict';

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const LOGS_DIR = path.resolve(process.env.LOGS_DIR || path.join(process.cwd(), 'writable', 'logs'));
const LOG_EXTENSIONS = (process.env.LOG_EXTENSIONS || '.log,.json,.txt')
    .split(',')
    .map((ext) => ext.trim().toLowerCase())
    .filter(Boolean);

const SERVER_INFO = { name: 'local-logs', version: '2.0.0' };
const PROTOCOL_VERSION = '2024-11-05';

// ---------------------------------------------------------------------------
// Log-file helpers
// ---------------------------------------------------------------------------

function listLogFiles() {
    if (!fs.existsSync(LOGS_DIR)) {
        throw new Error(`LOGS_DIR does not exist: ${LOGS_DIR}`);
    }

    return fs.readdirSync(LOGS_DIR)
        .filter((name) => LOG_EXTENSIONS.includes(path.extname(name).toLowerCase()))
        .map((name) => {
            const stat = fs.statSync(path.join(LOGS_DIR, name));
            return { name, sizeBytes: stat.size, modified: stat.mtime.toISOString() };
        })
        .sort((a, b) => b.modified.localeCompare(a.modified));
}

function resolveLogFile(name) {
    const resolved = path.resolve(LOGS_DIR, name);
    if (!resolved.startsWith(LOGS_DIR + path.sep)) {
        throw new Error(`Path escapes LOGS_DIR: ${name}`);
    }
    if (!LOG_EXTENSIONS.includes(path.extname(resolved).toLowerCase())) {
        throw new Error(`Extension not allowed: ${name}`);
    }
    if (!fs.existsSync(resolved)) {
        throw new Error(`Log file not found: ${name}`);
    }
    return resolved;
}

const TAIL_READ_BYTES = 5 * 1024 * 1024; // read at most the last 5 MB when tailing

function tailFile(name, lines) {
    const filePath = resolveLogFile(name);
    const size = fs.statSync(filePath).size;
    const readBytes = Math.min(size, TAIL_READ_BYTES);

    const buffer = Buffer.alloc(readBytes);
    const fd = fs.openSync(filePath, 'r');
    try {
        fs.readSync(fd, buffer, 0, readBytes, size - readBytes);
    } finally {
        fs.closeSync(fd);
    }

    const allLines = buffer.toString('utf8').split('\n');
    if (allLines.at(-1) === '') {
        allLines.pop();
    }
    if (readBytes < size) {
        allLines.shift(); // first line is likely truncated mid-line
    }
    return allLines.slice(-lines);
}

async function eachLine(filePath, onLine) {
    const stream = fs.createReadStream(filePath, { encoding: 'utf8' });
    const lineReader = readline.createInterface({ input: stream, crlfDelay: Infinity });
    let lineNumber = 0;
    for await (const line of lineReader) {
        lineNumber++;
        if (onLine(line, lineNumber) === false) {
            lineReader.close();
            stream.destroy();
            break;
        }
    }
}

async function searchLogs(pattern, fileName, maxResults) {
    const regex = new RegExp(pattern, 'i');
    const files = fileName ? [fileName] : listLogFiles().map((f) => f.name);
    const matches = [];

    for (const name of files) {
        if (matches.length >= maxResults) {
            break;
        }
        await eachLine(resolveLogFile(name), (line, lineNumber) => {
            if (regex.test(line)) {
                matches.push({ file: name, line: lineNumber, text: line.slice(0, 2000) });
            }
            return matches.length < maxResults;
        });
    }

    return matches;
}

async function getErrorSummary(maxEntries) {
    const errorLevels = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    const summary = { totalErrors: 0, byLevel: {}, recent: [] };

    for (const { name } of listLogFiles()) {
        await eachLine(resolveLogFile(name), (line) => {
            if (!line.trim()) {
                return true;
            }
            const level = detectErrorLevel(line, errorLevels);
            if (level === null) {
                return true;
            }
            summary.totalErrors++;
            summary.byLevel[level] = (summary.byLevel[level] || 0) + 1;
            if (summary.recent.length < maxEntries) {
                summary.recent.push({ file: name, level, text: line.slice(0, 2000) });
            }
            return true;
        });
    }

    return summary;
}

function detectErrorLevel(line, errorLevels) {
    try {
        const entry = JSON.parse(line);
        const level = String(entry.level_name || entry.level || '').toUpperCase();
        return errorLevels.includes(level) ? level : null;
    } catch {
        const match = errorLevels.find((lvl) => line.toUpperCase().includes(lvl));
        return match || null;
    }
}

// ---------------------------------------------------------------------------
// MCP tool definitions
// ---------------------------------------------------------------------------

const TOOLS = [
    {
        name: 'get_log_files',
        description: 'List all available log files in LOGS_DIR with size and modification time, newest first.',
        inputSchema: { type: 'object', properties: {}, additionalProperties: false },
        handler: () => listLogFiles(),
    },
    {
        name: 'read_log_file',
        description: 'Read the last N lines of a log file.',
        inputSchema: {
            type: 'object',
            properties: {
                file: { type: 'string', description: 'Log file name (e.g. app-2026-06-05.json)' },
                lines: { type: 'integer', description: 'Number of trailing lines to return (default 100)' },
            },
            required: ['file'],
            additionalProperties: false,
        },
        handler: (args) => tailFile(args.file, args.lines || 100),
    },
    {
        name: 'tail_log',
        description: 'Return the most recent lines of a log file (snapshot tail).',
        inputSchema: {
            type: 'object',
            properties: {
                file: { type: 'string', description: 'Log file name; omit to tail the most recently modified file' },
                lines: { type: 'integer', description: 'Number of trailing lines to return (default 50)' },
            },
            additionalProperties: false,
        },
        handler: (args) => {
            const file = args.file || listLogFiles()[0]?.name;
            if (!file) {
                throw new Error('No log files found');
            }
            return { file, lines: tailFile(file, args.lines || 50) };
        },
    },
    {
        name: 'search_logs',
        description: 'Search for a pattern (case-insensitive regex) across log files. Useful for correlation IDs and error codes.',
        inputSchema: {
            type: 'object',
            properties: {
                pattern: { type: 'string', description: 'Regex pattern to search for' },
                file: { type: 'string', description: 'Restrict search to one file (optional)' },
                maxResults: { type: 'integer', description: 'Maximum matches to return (default 100)' },
            },
            required: ['pattern'],
            additionalProperties: false,
        },
        handler: (args) => searchLogs(args.pattern, args.file, args.maxResults || 100),
    },
    {
        name: 'get_error_summary',
        description: 'Summarize ERROR/CRITICAL/ALERT/EMERGENCY entries across all log files (counts by level + recent samples).',
        inputSchema: {
            type: 'object',
            properties: {
                maxEntries: { type: 'integer', description: 'Maximum recent error entries to include (default 20)' },
            },
            additionalProperties: false,
        },
        handler: (args) => getErrorSummary(args.maxEntries || 20),
    },
];

// ---------------------------------------------------------------------------
// JSON-RPC over stdio (newline-delimited, per MCP stdio transport)
// ---------------------------------------------------------------------------

process.stdout.on('error', (error) => {
    if (error.code === 'EPIPE') {
        process.exit(0); // client went away; exit quietly
    }
    throw error;
});

function send(message) {
    process.stdout.write(JSON.stringify(message) + '\n');
}

function handleRequest(request) {
    const { id, method, params } = request;

    if (method === 'initialize') {
        return send({
            jsonrpc: '2.0',
            id,
            result: {
                protocolVersion: params?.protocolVersion || PROTOCOL_VERSION,
                capabilities: { tools: {} },
                serverInfo: SERVER_INFO,
            },
        });
    }

    if (method === 'ping') {
        return send({ jsonrpc: '2.0', id, result: {} });
    }

    if (method === 'tools/list') {
        return send({
            jsonrpc: '2.0',
            id,
            result: { tools: TOOLS.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })) },
        });
    }

    if (method === 'tools/call') {
        return handleToolCall(id, params);
    }

    if (id !== undefined) {
        send({ jsonrpc: '2.0', id, error: { code: -32601, message: `Method not found: ${method}` } });
    }
    // Notifications (no id) such as notifications/initialized are ignored.
}

let pendingCalls = 0;

async function handleToolCall(id, params) {
    const tool = TOOLS.find((t) => t.name === params?.name);
    if (!tool) {
        return send({ jsonrpc: '2.0', id, error: { code: -32602, message: `Unknown tool: ${params?.name}` } });
    }

    pendingCalls++;
    try {
        const result = await tool.handler(params.arguments || {});
        send({
            jsonrpc: '2.0',
            id,
            result: { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] },
        });
    } catch (error) {
        send({
            jsonrpc: '2.0',
            id,
            result: { content: [{ type: 'text', text: `Error: ${error.message}` }], isError: true },
        });
    } finally {
        pendingCalls--;
    }
}

const rl = readline.createInterface({ input: process.stdin, terminal: false });

rl.on('line', (line) => {
    if (!line.trim()) {
        return;
    }
    try {
        handleRequest(JSON.parse(line));
    } catch {
        send({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error' } });
    }
});

rl.on('close', function drainThenExit() {
    if (pendingCalls === 0) {
        process.exit(0);
    }
    setTimeout(drainThenExit, 50); // let in-flight tool calls finish responding
});
