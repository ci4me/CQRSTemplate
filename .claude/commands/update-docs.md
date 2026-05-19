# /update-docs

Updates project documentation after code changes following the template modification protocol.

## Usage

```
/update-docs {change-type}
```

## Arguments

- **change-type**: Type of change made
  - `pattern` - Changed architectural pattern
  - `file-structure` - Changed file organization
  - `quality-standard` - Changed quality requirements
  - `all` - Major refactoring affecting multiple areas

## Examples

```
/update-docs pattern
/update-docs file-structure
/update-docs quality-standard
/update-docs all
```

## Implementation

1. Identify what changed in Cookie domain
2. Follow `.claude/documentation/TEMPLATE_MODIFICATION_PROTOCOL.md`
3. Update documentation in order:
   - ARCHITECTURE_DECISIONS.md (if pattern changed)
   - COMPLETE_FILE_INVENTORY.md (if files changed)
   - Protocol documents
   - .claude/CLAUDE.md
   - Root documentation
4. Update affected agents
5. Update affected skills
6. Verify consistency across all docs

## Pattern Change Example

```
📝 Updating Documentation: Pattern Change

Change Detected: Value Object factory methods

Old Pattern:
  - fromString() + fromFloat()

New Pattern:
  - Single from() method

Files to Update:
1. ✅ Cookie domain updated (source of truth)
2. ✅ ARCHITECTURE_DECISIONS.md - Added ADR #13
3. ✅ DOMAIN_CREATION_PROTOCOL.md - Updated Step 2
4. ✅ PROPERTY_ADDITION_PROTOCOL.md - Updated Step 1
5. ✅ BUSINESS_RULE_PROTOCOL.md - Updated examples
6. ✅ ddd-specialist.md - Updated value object section
7. ✅ domain-scaffolding/SKILL.md - Updated Step 2
8. ✅ ADDING_DOMAINS.md - Updated examples
9. ✅ MODIFYING_ENTITIES.md - Updated value object section

Verification:
- grep -r "fromString\\|fromFloat" .claude/ → No old references found
- All examples consistent across docs ✅

Documentation update complete!
```

## File Structure Change Example

```
📝 Updating Documentation: File Structure Change

Change Detected: Added Specifications directory

Files Added:
- app/Domain/Cookie/Specifications/CookieSpecification.php
- tests/Unit/Domain/Cookie/Specifications/CookieSpecificationTest.php

Files to Update:
1. ✅ COMPLETE_FILE_INVENTORY.md
   - File count: 45 → 47
   - Added Specifications section
   - Updated Cookie domain tree

2. ✅ DOMAIN_CREATION_PROTOCOL.md
   - Added Step for Specifications
   - Updated completion checklist

3. ✅ domain-scaffolding/SKILL.md
   - Added Step for Specifications
   - Updated file counts

4. ✅ .claude/CLAUDE.md
   - Updated file count

Documentation update complete!
```

## Quality Standard Change Example

```
📝 Updating Documentation: Quality Standard Change

Change Detected: Test coverage requirement

Old Standard: 90% minimum
New Standard: 95% minimum

Files to Update:
1. ✅ test-specialist.md - Updated minimum to 95%
2. ✅ TESTING_GUIDELINES.md - Updated coverage section
3. ✅ DOMAIN_CREATION_PROTOCOL.md - Updated Step 15
4. ✅ PROPERTY_ADDITION_PROTOCOL.md - Updated validation
5. ✅ ARCHITECTURE_DECISIONS.md - Updated ADR about coverage
6. ✅ .claude/CLAUDE.md - Updated rejection policy
7. ✅ .claude/instructions.md - Updated post-execution rules

Documentation update complete!
```

## Checklist

Generates checklist from TEMPLATE_MODIFICATION_PROTOCOL.md:

```
Documentation Update Checklist:

- [ ] Cookie domain updated (source of truth)
- [ ] Cookie domain passes all quality checks
- [ ] ARCHITECTURE_DECISIONS.md updated
- [ ] COMPLETE_FILE_INVENTORY.md updated
- [ ] Protocol documents updated
- [ ] Affected specialists updated
- [ ] .claude/CLAUDE.md updated
- [ ] .claude/instructions.md updated (if quality changed)
- [ ] Skills updated
- [ ] Root docs updated
- [ ] No old references remain (grep verified)
- [ ] All examples consistent

✅ {X}/{Y} items completed
```

## See Also

- `.claude/documentation/TEMPLATE_MODIFICATION_PROTOCOL.md`
- Cookie domain (source of truth)
