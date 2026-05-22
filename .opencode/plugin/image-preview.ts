import { closeSync, existsSync, openSync } from "node:fs"
import { access, constants, stat } from "node:fs/promises"
import { basename, extname, isAbsolute, relative, resolve } from "node:path"
import { fileURLToPath, pathToFileURL } from "node:url"
import { spawn } from "node:child_process"
import type { Plugin } from "@opencode-ai/plugin"
import { tool } from "@opencode-ai/plugin"

const IMAGE_EXTENSIONS = new Set([
  ".avif",
  ".bmp",
  ".gif",
  ".jpeg",
  ".jpg",
  ".png",
  ".svg",
  ".webp",
])

type RenderMode = "auto" | "chafa" | "sixel" | "kitty" | "none"

const mimeByExtension: Record<string, string> = {
  ".avif": "image/avif",
  ".bmp": "image/bmp",
  ".gif": "image/gif",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".webp": "image/webp",
}

function commandExists(command: string) {
  const pathEnv = process.env.PATH ?? ""
  return pathEnv.split(":").some((dir) => existsSync(resolve(dir, command)))
}

function resolveImagePath(inputPath: string, directory: string) {
  if (inputPath.startsWith("file://")) return fileURLToPath(inputPath)
  return isAbsolute(inputPath) ? inputPath : resolve(directory, inputPath)
}

async function run(command: string, args: string[], timeoutMs = 10_000) {
  return await new Promise<{ code: number | null; stdout: string; stderr: string }>((resolveProcess) => {
    const child = spawn(command, args, {
      env: process.env,
      stdio: ["ignore", "pipe", "pipe"],
    })
    const timer = setTimeout(() => child.kill("SIGTERM"), timeoutMs)
    const stdout: Buffer[] = []
    const stderr: Buffer[] = []

    child.stdout.on("data", (chunk) => stdout.push(Buffer.from(chunk)))
    child.stderr?.on("data", (chunk) => stderr.push(Buffer.from(chunk)))
    child.on("close", (code) => {
      clearTimeout(timer)
      resolveProcess({
        code,
        stdout: Buffer.concat(stdout).toString("utf8"),
        stderr: Buffer.concat(stderr).toString("utf8"),
      })
    })
    child.on("error", (error) => {
      clearTimeout(timer)
      resolveProcess({ code: 127, stdout: "", stderr: error.message })
    })
  })
}

async function runDirect(command: string, args: string[], timeoutMs = 10_000) {
  return await new Promise<{ code: number | null; stderr: string }>((resolveProcess) => {
    let tty: number | undefined
    try {
      tty = openSync("/dev/tty", "w")
    } catch (error) {
      resolveProcess({ code: 1, stderr: error instanceof Error ? error.message : "Unable to open /dev/tty" })
      return
    }

    const child = spawn(command, args, {
      env: process.env,
      stdio: ["ignore", tty, "pipe"],
    })
    const timer = setTimeout(() => child.kill("SIGTERM"), timeoutMs)
    const stderr: Buffer[] = []

    child.stderr?.on("data", (chunk) => stderr.push(Buffer.from(chunk)))
    child.on("close", (code) => {
      clearTimeout(timer)
      closeSync(tty)
      resolveProcess({ code, stderr: Buffer.concat(stderr).toString("utf8") })
    })
    child.on("error", (error) => {
      clearTimeout(timer)
      closeSync(tty)
      resolveProcess({ code: 127, stderr: error.message })
    })
  })
}

function pickMode(requested: RenderMode) {
  if (requested !== "auto") return requested

  const term = process.env.TERM ?? ""
  const termProgram = process.env.TERM_PROGRAM ?? ""
  const isMosh = Boolean(process.env.MOSH_IP || process.env.MOSH_KEY || process.env.MOSH_PORT)

  if (!isMosh && (term.includes("kitty") || termProgram === "WezTerm") && commandExists("kitty")) return "kitty"
  if (!isMosh && commandExists("chafa")) return "sixel"
  if (commandExists("chafa")) return "chafa"

  return "none"
}

async function renderPreview(imagePath: string, mode: RenderMode, width: number, height: number) {
  const selected = pickMode(mode)

  if (selected === "kitty") {
    const result = await run("kitty", ["+kitten", "icat", "--align", "left", "--place", `${width}x${height}@0x0`, imagePath])
    if (result.code === 0 && result.stdout.trim()) return { selected, preview: result.stdout, error: "" }
    return { selected, preview: "", error: result.stderr || "kitty icat failed" }
  }

  if (selected === "sixel") {
    const result = await run("chafa", ["--format=sixels", `--size=${width}x${height}`, imagePath])
    if (result.code === 0 && result.stdout.trim()) return { selected, preview: result.stdout, error: "" }

    const fallback = await run("chafa", [`--size=${width}x${height}`, imagePath])
    if (fallback.code === 0 && fallback.stdout.trim()) return { selected: "chafa" as const, preview: fallback.stdout, error: result.stderr }
    return { selected, preview: "", error: fallback.stderr || result.stderr || "chafa failed" }
  }

  if (selected === "chafa") {
    const result = await run("chafa", [`--size=${width}x${height}`, imagePath])
    if (result.code === 0 && result.stdout.trim()) return { selected, preview: result.stdout, error: "" }
    return { selected, preview: "", error: result.stderr || "chafa failed" }
  }

  return { selected, preview: "", error: "No supported renderer found. Install chafa, or use SSH with Kitty/WezTerm." }
}

async function renderPreviewDirect(imagePath: string, mode: RenderMode, width: number, height: number) {
  const selected = pickMode(mode)

  if (selected === "kitty") {
    const result = await runDirect("kitty", ["+kitten", "icat", "--align", "left", "--place", `${width}x${height}@0x0`, imagePath])
    return { selected, error: result.code === 0 ? "" : result.stderr || "kitty icat failed" }
  }

  if (selected === "sixel") {
    const result = await runDirect("chafa", ["--format=sixels", `--size=${width}x${height}`, imagePath])
    if (result.code === 0) return { selected, error: "" }

    const fallback = await runDirect("chafa", ["--format=symbols", `--size=${width}x${height}`, imagePath])
    return { selected: "chafa" as const, error: fallback.code === 0 ? result.stderr : fallback.stderr || result.stderr || "chafa failed" }
  }

  if (selected === "chafa") {
    const result = await runDirect("chafa", ["--format=symbols", `--size=${width}x${height}`, imagePath])
    return { selected, error: result.code === 0 ? "" : result.stderr || "chafa failed" }
  }

  return { selected, error: "No supported renderer found. Install chafa, or use SSH with Kitty/WezTerm." }
}

export default (async () => {
  return {
    tool: {
      image_preview: tool({
        description:
          "Render an image artifact directly in the OpenCode terminal when possible. Works best over SSH; over MOSH it falls back to chafa text/ANSI preview.",
        args: {
          path: tool.schema.string().describe("Image path or file:// URL to preview."),
          mode: tool.schema.enum(["auto", "chafa", "sixel", "kitty", "none"]).default("auto").describe("Preview renderer."),
          width: tool.schema.number().int().min(10).max(240).default(80).describe("Preview width in terminal cells."),
          height: tool.schema.number().int().min(4).max(120).default(30).describe("Preview height in terminal cells."),
          direct: tool.schema.boolean().default(true).describe("Write renderer escape sequences directly to /dev/tty instead of returning them as text."),
        },
        async execute(args, context) {
          const imagePath = resolveImagePath(args.path, context.directory)
          const extension = extname(imagePath).toLowerCase()

          if (!IMAGE_EXTENSIONS.has(extension)) {
            return `Unsupported image extension: ${extension || "(none)"}`
          }

          await access(imagePath, constants.R_OK)
          const imageStat = await stat(imagePath)
          if (!imageStat.isFile()) return `Not a file: ${imagePath}`

          const rendered = args.direct
            ? { ...(await renderPreviewDirect(imagePath, args.mode, args.width, args.height)), preview: "" }
            : await renderPreview(imagePath, args.mode, args.width, args.height)
          const { selected, preview, error } = rendered
          const relativePath = relative(context.worktree, imagePath)
          const fileUrl = pathToFileURL(imagePath).toString()
          const details = [
            `Image: ${relativePath.startsWith("..") ? imagePath : relativePath}`,
            `Renderer: ${selected}`,
            `Direct TTY: ${args.direct ? "yes" : "no"}`,
            `Size: ${imageStat.size} bytes`,
            error ? `Renderer note: ${error.trim()}` : "",
          ]
            .filter(Boolean)
            .join("\n")

          return {
            title: `Image preview: ${basename(imagePath)}`,
            output: preview ? `${preview}\n${details}` : details,
            metadata: {
              path: imagePath,
              renderer: selected,
              size: imageStat.size,
            },
            attachments: [
              {
                type: "file",
                mime: mimeByExtension[extension] ?? "application/octet-stream",
                url: fileUrl,
                filename: basename(imagePath),
              },
            ],
          }
        },
      }),
    },
  }
}) satisfies Plugin
