#!/usr/bin/env python3
"""
Validate execution JSON files against JSON schemas.

Usage:
    python validate_execution.py <execution-id>
    python validate_execution.py exec-20251022-143000

This script validates:
- tasks.json against task-schema.json
- execution.json against execution-schema.json
- metadata.json against metadata-schema.json (if exists)
"""

import json
import sys
from pathlib import Path
from typing import Dict, Any, List, Tuple

try:
    from jsonschema import validate, ValidationError, Draft7Validator
    from jsonschema.exceptions import SchemaError
except ImportError:
    print("Error: jsonschema package not found.")
    print("Install it with: pip install jsonschema")
    sys.exit(1)


class ExecutionValidator:
    """Validates execution files against JSON schemas."""

    def __init__(self, execution_id: str):
        self.execution_id = execution_id
        self.project_root = Path(__file__).parent.parent.parent.parent.parent
        self.execution_dir = self.project_root / ".claude" / "planning" / "executions" / execution_id
        self.schema_dir = self.project_root / ".claude" / "skills" / "strategic-planner" / "schemas"
        self.errors: List[str] = []
        self.warnings: List[str] = []

    def load_json_file(self, file_path: Path) -> Dict[str, Any] | None:
        """Load and parse JSON file."""
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except FileNotFoundError:
            self.errors.append(f"File not found: {file_path}")
            return None
        except json.JSONDecodeError as e:
            self.errors.append(f"Invalid JSON in {file_path}: {e}")
            return None

    def validate_against_schema(
        self,
        data: Dict[str, Any],
        schema: Dict[str, Any],
        file_name: str
    ) -> bool:
        """Validate data against JSON schema."""
        try:
            validator = Draft7Validator(schema)
            errors = list(validator.iter_errors(data))

            if errors:
                self.errors.append(f"\n{file_name} validation failed:")
                for error in errors:
                    path = " -> ".join(str(p) for p in error.path) if error.path else "root"
                    self.errors.append(f"  - At '{path}': {error.message}")
                return False

            return True
        except SchemaError as e:
            self.errors.append(f"Schema error for {file_name}: {e}")
            return False

    def validate_tasks(self) -> bool:
        """Validate tasks.json against task-schema.json."""
        print("Validating tasks.json...")

        tasks_file = self.execution_dir / "tasks.json"
        schema_file = self.schema_dir / "task-schema.json"

        tasks = self.load_json_file(tasks_file)
        schema = self.load_json_file(schema_file)

        if tasks is None or schema is None:
            return False

        valid = self.validate_against_schema(tasks, schema, "tasks.json")

        if valid:
            # Additional atomicity checks
            task_count = len(tasks)
            violations = []

            for task_id, task in tasks.items():
                # Check files ≤ 3
                if len(task.get('files', [])) > 3:
                    violations.append(f"{task_id}: {len(task['files'])} files (max 3)")

                # Check duration ≤ 30 minutes
                duration = task.get('duration_minutes', 0)
                if duration > 30:
                    violations.append(f"{task_id}: {duration} min (max 30)")

            if violations:
                self.warnings.append(f"\nAtomicity violations found:")
                for v in violations:
                    self.warnings.append(f"  - {v}")

            print(f"✓ tasks.json is valid ({task_count} tasks)")
            return True

        return False

    def validate_execution(self) -> bool:
        """Validate execution.json against execution-schema.json."""
        print("Validating execution.json...")

        exec_file = self.execution_dir / "execution.json"
        schema_file = self.schema_dir / "execution-schema.json"

        execution = self.load_json_file(exec_file)
        schema = self.load_json_file(schema_file)

        if execution is None or schema is None:
            return False

        valid = self.validate_against_schema(execution, schema, "execution.json")

        if valid:
            # Additional consistency checks
            total_tasks = execution.get('total_tasks', 0)
            mapping_count = len(execution.get('todowrite_mapping', {}))

            if total_tasks != mapping_count:
                self.warnings.append(
                    f"\nTodoWrite mapping mismatch: "
                    f"{mapping_count} mapped tasks vs {total_tasks} total tasks"
                )

            completed = execution.get('completed_tasks', 0)
            pending = execution.get('pending_tasks', 0)

            if completed + pending != total_tasks:
                self.warnings.append(
                    f"\nTask count mismatch: "
                    f"{completed} completed + {pending} pending != {total_tasks} total"
                )

            status = execution.get('status')
            print(f"✓ execution.json is valid (status: {status})")
            return True

        return False

    def validate_metadata(self) -> bool:
        """Validate metadata.json against metadata-schema.json (optional)."""
        metadata_file = self.execution_dir / "metadata.json"

        if not metadata_file.exists():
            print("ℹ metadata.json not found (optional)")
            return True

        print("Validating metadata.json...")

        schema_file = self.schema_dir / "metadata-schema.json"

        metadata = self.load_json_file(metadata_file)
        schema = self.load_json_file(schema_file)

        if metadata is None or schema is None:
            return False

        valid = self.validate_against_schema(metadata, schema, "metadata.json")

        if valid:
            complexity = metadata.get('complexity_level', 'UNKNOWN')
            risk = metadata.get('risk_level', 'UNKNOWN')
            print(f"✓ metadata.json is valid (complexity: {complexity}, risk: {risk})")
            return True

        return False

    def validate_all(self) -> bool:
        """Run all validations."""
        print(f"\n{'='*70}")
        print(f"Validating execution: {self.execution_id}")
        print(f"{'='*70}\n")

        if not self.execution_dir.exists():
            self.errors.append(f"Execution directory not found: {self.execution_dir}")
            return False

        # Run all validations
        tasks_valid = self.validate_tasks()
        execution_valid = self.validate_execution()
        metadata_valid = self.validate_metadata()

        # Print results
        print(f"\n{'='*70}")

        if self.warnings:
            print("\n⚠ WARNINGS:")
            for warning in self.warnings:
                print(warning)

        if self.errors:
            print("\n✗ VALIDATION FAILED:")
            for error in self.errors:
                print(error)
            print(f"\n{'='*70}\n")
            return False

        all_valid = tasks_valid and execution_valid and metadata_valid

        if all_valid:
            print("\n✓ ALL VALIDATIONS PASSED")
            if self.warnings:
                print("  (with warnings - review above)")

        print(f"{'='*70}\n")
        return all_valid


def main():
    """Main entry point."""
    if len(sys.argv) != 2:
        print("Usage: python validate_execution.py <execution-id>")
        print("Example: python validate_execution.py exec-20251022-143000")
        sys.exit(1)

    execution_id = sys.argv[1]

    # Validate execution ID format
    if not execution_id.startswith('exec-'):
        print(f"Error: Invalid execution ID format: {execution_id}")
        print("Expected format: exec-YYYYMMDD-HHMMSS")
        sys.exit(1)

    validator = ExecutionValidator(execution_id)
    success = validator.validate_all()

    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
