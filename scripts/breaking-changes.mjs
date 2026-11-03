// Manages the per-change upgrade notes in breaking-changes/. See breaking-changes/AGENT.md.
//
//   node scripts/breaking-changes.mjs check   Pull request check. Needs PR_TITLE and BASE_SHA.
//   node scripts/breaking-changes.mjs bundle  Release step. Moves the notes into the newest
//                                             CHANGELOG.md version and deletes the files.
//   node scripts/breaking-changes.mjs notes <version>
//                                             Prints that version's CHANGELOG.md section.
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "..");
const notesDirectory = "breaking-changes";

// The template starts with "_" and AGENT.md is guidance; every other Markdown file is a note.
function isNote(name) {
  if (name === "AGENT.md") return false;
  if (name.startsWith("_")) return false;
  return name.endsWith(".md");
}

function fail(message) {
  console.error(`::error::${message}`);
  process.exit(1);
}

function check() {
  const { PR_TITLE: title, BASE_SHA: base } = process.env;
  if (!title) fail("PR_TITLE must be set.");
  if (!base) fail("BASE_SHA must be set.");

  const added = execFileSync(
    "git",
    ["diff", "--name-only", "--diff-filter=A", `${base}...HEAD`, "--", notesDirectory],
    { cwd: root, encoding: "utf8" },
  )
    .split("\n")
    .filter((file) => path.dirname(file) === notesDirectory)
    .filter((file) => isNote(path.basename(file)));
  const breaking = /^\w+!:/.test(title);

  if (breaking) {
    if (added.length === 0) {
      fail(
        `The title marks this pull request as breaking, but it adds no file to ${notesDirectory}/. Copy ${notesDirectory}/_template.md and describe how to upgrade.`,
      );
    }
  } else {
    if (added.length > 0) {
      fail(
        `This pull request adds ${added.join(", ")}, but its title is not marked as breaking. Add "!" after the type, e.g. "feat!: ...", so the release bumps the minor version.`,
      );
    }
  }
  for (const file of added) {
    if (!readFileSync(path.join(root, file), "utf8").startsWith("# ")) {
      fail(`${file} must start with a "# " title.`);
    }
  }

  console.log(
    breaking ? `Breaking change documented in ${added.join(", ")}.` : "Not a breaking change.",
  );
}

// Nests a note under "### Upgrade guide": strips guidance comments and turns "#" into "####".
function toChangelog(markdown) {
  let fenced = false;

  return markdown
    .replace(/<!--[\s\S]*?-->\n*/g, "")
    .split("\n")
    .map((line) => {
      if (/^(```|~~~)/.test(line)) fenced = !fenced;
      if (fenced) return line;
      return /^#{1,3} /.test(line) ? `###${line}` : line;
    })
    .join("\n")
    .trim();
}

function bundle() {
  const files = readdirSync(path.join(root, notesDirectory)).filter(isNote).sort();
  if (files.length === 0) {
    console.log("No breaking changes to bundle.");
    return;
  }

  const changelogPath = path.join(root, "CHANGELOG.md");
  const changelog = readFileSync(changelogPath, "utf8");
  const versions = [...changelog.matchAll(/^## /gm)].map((match) => match.index);
  if (versions.length === 0)
    fail("CHANGELOG.md has no version heading to add the upgrade guide to.");

  // The guide goes at the end of the newest version, after release-please's own sections.
  const end = versions[1] ?? changelog.length;
  const notes = files.map((file) => toChangelog(readFileSync(path.join(root, notesDirectory, file), "utf8")));
  const guide = `### Upgrade guide\n\n${notes.join("\n\n")}\n`;
  const head = changelog.slice(0, end).trimEnd();
  const rest = changelog.slice(end);

  writeFileSync(changelogPath, `${head}\n\n${guide}${rest === "" ? "" : `\n${rest}`}`);
  for (const file of files) rmSync(path.join(root, notesDirectory, file));

  console.log(`Bundled ${files.length} breaking change(s) into CHANGELOG.md: ${files.join(", ")}`);
}

// Prints one version's CHANGELOG.md section, upgrade guide included, for its GitHub release.
// release-please writes release notes from commits, so they lack the guide bundled above.
function notes() {
  const version = process.argv[3];
  if (!version) fail("Usage: node scripts/breaking-changes.mjs notes <version>");

  const changelog = readFileSync(path.join(root, "CHANGELOG.md"), "utf8");
  const sections = changelog.split(/^(?=## )/m);
  const heading = new RegExp(`^## \\[?${version.replaceAll(".", "\\.")}[\\] ]`);
  const section = sections.find((part) => heading.test(part));
  if (!section) fail(`CHANGELOG.md has no section for version ${version}.`);

  process.stdout.write(`${section.trim()}\n`);
}

const commands = { check, bundle, notes };
const command = commands[process.argv[2]];
if (!command) fail(`Usage: node scripts/breaking-changes.mjs <${Object.keys(commands).join("|")}>`);
command();
