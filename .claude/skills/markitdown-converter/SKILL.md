---
name: markitdown-converter
description: Convert Office documents (Word, Excel, PowerPoint), PDFs, images, audio files, and other formats to Markdown using MarkItDown MCP. Use when processing documents for AI, extracting content, or creating Markdown documentation from various file formats.
allowed-tools: Read, Write, Bash, Grep, Glob
---

# MarkItDown Document Converter Skill

This skill provides powerful file-to-Markdown conversion using the MarkItDown MCP server, enabling conversion of Office documents, PDFs, images, audio, and more into clean, AI-readable Markdown format.

## When to Use This Skill

Activate this skill when you need to:
- Convert Word documents (.docx, .doc) to Markdown
- Convert Excel spreadsheets (.xlsx, .xls) to Markdown tables
- Convert PowerPoint presentations (.pptx, .ppt) to Markdown
- Extract text from PDF files
- Perform OCR on images to extract text
- Transcribe audio files to text/Markdown
- Batch convert multiple documents
- Process HTML content to Markdown
- Extract tables from documents
- Create AI-processable content from various formats

## Supported File Formats

### Office Documents
- **Word**: .docx, .doc → Preserves headings, lists, tables, formatting
- **Excel**: .xlsx, .xls, .csv → Converts to Markdown tables
- **PowerPoint**: .pptx, .ppt → Slides to sections with content

### PDF Documents
- **PDF**: .pdf → Extract text and structure with proper formatting

### Images (with OCR)
- **Formats**: .png, .jpg, .jpeg, .gif, .bmp, .tiff
- **Capability**: Optical Character Recognition (OCR) to extract text

### Audio Files
- **Formats**: .wav, .mp3, .m4a, .flac
- **Capability**: Speech-to-text transcription

### Web Content
- **HTML**: .html, .htm → Convert to clean Markdown
- **URLs**: Convert web pages to Markdown

### Archives
- **ZIP**: .zip → Extract and convert contents

### Other
- **Plain Text**: .txt, .md, .rtf
- **Code Files**: Syntax highlighting preserved

## Core Capabilities

### 1. Document-to-Markdown Conversion
Convert any supported format to Markdown while preserving structure.

**MCP Tool:**
```
convert_to_markdown(uri: string)
```

**URI Formats:**
- `file:///absolute/path/to/file.docx`
- `http://example.com/document.pdf`
- `https://example.com/page.html`
- `data:...` (base64 encoded content)

### 2. Structure Preservation
Maintains document hierarchy and formatting:
- Headings (H1-H6)
- Lists (ordered and unordered)
- Tables (formatted as Markdown tables)
- Code blocks (with syntax highlighting)
- Links and images
- Bold, italic, and other formatting

### 3. Batch Processing
Convert multiple files in a single operation.

### 4. Content Extraction
Extract specific content types:
- Tables → Markdown tables
- Images → References or OCR text
- Text → Clean paragraphs
- Code → Syntax-highlighted blocks

## Complete Workflow Examples

### Example 1: Convert Word Document

```markdown
## Scenario: Convert technical documentation from .docx to .md

User: "Convert technical-spec.docx to Markdown"

### Steps:

#### Step 1: Locate File
Find file: /home/user/documents/technical-spec.docx

#### Step 2: Convert to Markdown
Use MarkItDown MCP:
convert_to_markdown("file:///home/user/documents/technical-spec.docx")

#### Step 3: Review Converted Content
markdown
# Technical Specification

## Overview
This document outlines the technical requirements...

## Architecture

### System Components
1. Frontend Application
2. Backend API
3. Database Layer

### Component Details

| Component | Technology | Purpose |
|-----------|------------|---------|
| Frontend | React 18 | User interface |
| Backend | Node.js | API server |
| Database | PostgreSQL | Data storage |

## API Endpoints

### GET /api/users
Returns list of all users...


#### Step 4: Save to File
Save converted Markdown to: technical-spec.md

### Result:
✅ Successfully converted technical-spec.docx to Markdown
✅ Preserved all headings, tables, and formatting
✅ Saved as technical-spec.md
```

### Example 2: Extract Tables from Excel

```markdown
## Scenario: Convert Excel spreadsheet to Markdown tables

User: "Convert sales-data.xlsx to Markdown tables"

### Steps:

#### Step 1: Convert Spreadsheet
convert_to_markdown("file:///home/user/data/sales-data.xlsx")

#### Step 2: Received Markdown
markdown
# sales-data

## Sheet1

| Product | Q1 Sales | Q2 Sales | Q3 Sales | Q4 Sales | Total |
|---------|----------|----------|----------|----------|-------|
| Widget A | 1,250 | 1,450 | 1,650 | 2,100 | 6,450 |
| Widget B | 890 | 920 | 1,100 | 1,200 | 4,110 |
| Widget C | 2,300 | 2,150 | 2,400 | 2,900 | 9,750 |

## Sheet2

| Region | Revenue | Growth |
|--------|---------|--------|
| North | $125,000 | 15% |
| South | $98,500 | 8% |
| East | $143,200 | 22% |
| West | $110,800 | 12% |


#### Step 3: Save and Process
- Saved to: sales-data.md
- Tables ready for AI analysis
- Can be parsed programmatically

### Result:
✅ Converted Excel with 2 sheets
✅ All tables formatted as Markdown
✅ Data structure preserved
```

### Example 3: PDF Text Extraction

```markdown
## Scenario: Extract content from PDF report

User: "Extract text from quarterly-report.pdf"

### Steps:

#### Step 1: Convert PDF
convert_to_markdown("file:///home/user/reports/quarterly-report.pdf")

#### Step 2: Review Extracted Content
markdown
# Q4 2024 Financial Report

## Executive Summary

Revenue increased by 23% compared to Q3 2024, driven by strong
performance in our enterprise segment...

## Key Metrics

- **Total Revenue**: $4.2M
- **Operating Expenses**: $2.1M
- **Net Profit**: $1.3M
- **Growth Rate**: 23% YoY

## Detailed Analysis

### Revenue Breakdown

| Segment | Revenue | % of Total |
|---------|---------|------------|
| Enterprise | $2.5M | 60% |
| SMB | $1.2M | 29% |
| Individual | $0.5M | 11% |

[Additional sections follow...]


#### Step 3: Save Extracted Content
Save to: quarterly-report.md

### Result:
✅ Extracted all text from PDF
✅ Preserved structure and tables
✅ Clean Markdown format
```

### Example 4: Batch Document Conversion

```markdown
## Scenario: Convert all documents in a directory

User: "Convert all documents in /docs folder to Markdown"

### Steps:

#### Step 1: Find All Convertible Files
Scan directory: /home/user/docs/

Found:
- meeting-notes.docx
- budget.xlsx
- presentation.pptx
- report.pdf
- screenshot.png

#### Step 2: Convert Each File

**File 1: meeting-notes.docx**
convert_to_markdown("file:///home/user/docs/meeting-notes.docx")
✅ Saved to: meeting-notes.md

**File 2: budget.xlsx**
convert_to_markdown("file:///home/user/docs/budget.xlsx")
✅ Saved to: budget.md

**File 3: presentation.pptx**
convert_to_markdown("file:///home/user/docs/presentation.pptx")
✅ Saved to: presentation.md

**File 4: report.pdf**
convert_to_markdown("file:///home/user/docs/report.pdf")
✅ Saved to: report.md

**File 5: screenshot.png**
convert_to_markdown("file:///home/user/docs/screenshot.png")
✅ Saved to: screenshot.md (OCR text extracted)

#### Step 3: Generate Summary

### Conversion Summary:
- **Total files**: 5
- **Successful**: 5
- **Failed**: 0
- **Output directory**: /home/user/docs/markdown/

### Result:
✅ Batch converted 5 files
✅ All conversions successful
✅ Markdown files ready for processing
```

### Example 5: Image OCR

```markdown
## Scenario: Extract text from screenshot

User: "Extract text from error-screenshot.png"

### Steps:

#### Step 1: Verify Image File
File exists: error-screenshot.png (PNG image, 1920x1080)

#### Step 2: Perform OCR
convert_to_markdown("file:///home/user/screenshots/error-screenshot.png")

#### Step 3: Review Extracted Text
markdown
# Error Message

## Application Error

Fatal Error: Database Connection Failed

**Error Code:** DB_CONN_001

**Details:**
- Host: db.example.com:5432
- Database: production_db
- User: app_user
- Error: Connection timeout after 30 seconds

**Stack Trace:**
at DatabaseConnector.connect()
at ApplicationBootstrap.initialize()
at main()

**Timestamp:** 2024-10-25 09:45:32 UTC


#### Step 4: Use Extracted Text
- Error logged to system
- Text available for debugging
- Can be processed by AI for analysis

### Result:
✅ OCR successfully extracted text
✅ Structured as Markdown
✅ Ready for analysis
```

### Example 6: PowerPoint to Markdown

```markdown
## Scenario: Convert presentation to documentation

User: "Convert product-launch.pptx to Markdown documentation"

### Steps:

#### Step 1: Convert Presentation
convert_to_markdown("file:///home/user/presentations/product-launch.pptx")

#### Step 2: Review Converted Content
markdown
# Product Launch Presentation

## Slide 1: Introduction

### New Product: CloudSync Pro

Revolutionizing team collaboration

---

## Slide 2: Problem Statement

### Current Challenges
- Scattered files across platforms
- Version control issues
- Limited collaboration features
- Security concerns

---

## Slide 3: Our Solution

### CloudSync Pro Features
1. **Unified Storage** - All files in one place
2. **Real-time Sync** - Instant updates across devices
3. **Advanced Collaboration** - Comments, reviews, approvals
4. **Enterprise Security** - End-to-end encryption

---

## Slide 4: Pricing

| Plan | Price | Features |
|------|-------|----------|
| Starter | $10/mo | 100GB, 5 users |
| Professional | $25/mo | 1TB, 25 users |
| Enterprise | Custom | Unlimited |

---

[Additional slides...]


#### Step 3: Save as Documentation
Save to: product-launch.md

### Result:
✅ Converted 15-slide presentation
✅ Each slide is a section
✅ Tables and formatting preserved
✅ Ready to use as documentation
```

## Best Practices

### 1. File Path Handling
```
✅ GOOD:
- file:///home/user/docs/file.docx (absolute path)
- https://example.com/document.pdf (URL)

❌ BAD:
- ../relative/path/file.docx (relative path)
- ~/documents/file.docx (shell expansion)
```

### 2. Verify Before Converting
```
Before conversion:
□ File exists
□ File format is supported
□ File is not corrupted
□ File permissions are correct
```

### 3. Review Converted Content
```
After conversion:
□ Check headings are preserved
□ Verify tables formatted correctly
□ Ensure lists maintained
□ Validate links working
□ Review special characters
```

### 4. Handle Large Files
```
For files >10MB:
- Warn user about potential slow conversion
- Consider splitting into smaller sections
- Monitor conversion progress
- Provide status updates
```

## Integration with Other Skills

### With Context7
```
Use Case: Convert library docs to searchable Markdown

1. Download PDF documentation
2. Use MarkItDown to convert to Markdown
3. Use Context7 to index and search
4. Provide up-to-date, searchable docs
```

### With Serena
```
Use Case: Convert codebase documentation

1. MarkItDown: Convert Word/PDF docs to Markdown
2. Serena: Link docs to code symbols
3. Result: Searchable, code-linked documentation
```

### With Web Scraping
```
Use Case: Archive web content

1. Puppeteer: Navigate and download PDFs
2. MarkItDown: Convert PDFs to Markdown
3. Result: Archived content in Markdown format
```

## Common Issues & Solutions

### Issue: "File not found"
```
Causes:
- Incorrect file path
- File moved or deleted
- Permission issues

Solutions:
1. Use absolute paths
2. Verify file exists with ls/Read
3. Check file permissions
4. Use proper URI encoding
```

### Issue: "Unsupported format"
```
Causes:
- File extension not recognized
- Corrupted file
- Proprietary format

Solutions:
1. Check supported formats list
2. Try converting to standard format first
3. Verify file is not corrupted
4. Use alternative tool
```

### Issue: "Poor OCR quality"
```
Causes:
- Low resolution image
- Poor image quality
- Complex layout

Solutions:
1. Use higher resolution image
2. Improve image contrast/brightness
3. Crop to relevant area
4. Use manual review for critical text
```

### Issue: "Tables not formatted correctly"
```
Causes:
- Complex table structure
- Merged cells
- Nested tables

Solutions:
1. Simplify table structure in source
2. Manual adjustment of Markdown table
3. Split complex tables
4. Use HTML table fallback
```

## Performance Tips

1. **Batch Processing**: Convert multiple files concurrently
2. **Caching**: Save converted files to avoid re-conversion
3. **File Size**: Process large files during off-peak times
4. **Format Selection**: Use most appropriate source format

## Summary

The MarkItDown Document Converter Skill provides powerful, versatile file-to-Markdown conversion capabilities. It excels at processing Office documents, PDFs, images, and other formats into clean, structured Markdown for AI processing, documentation, or content analysis.

**Key Strengths:**
- 📚 **Multi-format**: 15+ file formats supported
- 🔄 **Batch Processing**: Convert multiple files at once
- 📊 **Table Support**: Excel/Word tables → Markdown tables
- 🖼️ **OCR**: Extract text from images
- 🎤 **Transcription**: Audio → Text conversion
- 🎯 **Structure Preservation**: Maintains document hierarchy
- 🚀 **Fast**: Efficient conversion pipeline
- 🤖 **AI-Ready**: Output optimized for AI processing

**Perfect For:**
- Converting documentation to Markdown
- Extracting content from PDFs
- Processing Office documents for AI
- OCR text extraction from images
- Audio transcription
- Batch document conversion
- Creating searchable content libraries

Use this skill whenever you need to **convert documents to Markdown** for AI processing, documentation, or content extraction!
