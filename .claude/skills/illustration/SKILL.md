---
name: illustration
description: Generate a ChatGPT image generation prompt in the MySpeedPuzzling brand illustration style. Use when the user wants to create a new illustration for the website.
argument-hint: "<description of the illustration, e.g. 'a marketplace scene with puzzle boxes for sale'>"
allowed-tools: Read, Write, Glob
---

# Generate Illustration Prompt

The user wants a new illustration prompt for ChatGPT image generation.

## Instructions

1. Read the style prefix from `docs/design-system/prompts/style-prefix.md`
2. Take the user's description from: $ARGUMENTS
3. Expand the description into a detailed subject block (2-4 sentences, specific visual details, what objects/people/elements to include)
4. Ask the user what aspect ratio they need:
   - 1:1 square (icons, spot illustrations)
   - 4:3 (feature sections)
   - 16:9 landscape (hero sections, wide banners)
5. Combine the style prefix + expanded subject + aspect ratio into one complete self-contained prompt
6. Save the full prompt as a new file in `docs/design-system/prompts/subjects/` with a descriptive kebab-case filename (e.g. `marketplace-scene.md`)
7. Output the full prompt so the user can copy it directly into ChatGPT

## File format

Use this structure for the saved file:

```markdown
# Subject: [Short title]

[One line description of where this illustration would be used]

## Full Prompt

\`\`\`
[STYLE PREFIX from style-prefix.md]

[EXPANDED SUBJECT DESCRIPTION]

Aspect ratio: [CHOSEN RATIO]
\`\`\`
```
