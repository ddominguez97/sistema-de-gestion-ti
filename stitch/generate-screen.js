/**
 * generate-screen.js — Generar pantallas UI con Google Stitch AI
 *
 * Uso:
 *   node generate-screen.js "Dashboard de inventario con sidebar oscuro"
 *   node generate-screen.js "Login page con logo NAGSA y color naranja #e05816"
 *   node generate-screen.js "Tabla de activos con filtros y paginacion" MOBILE
 */

import "dotenv/config";
import { stitch } from "@google/stitch-sdk";
import { writeFileSync, mkdirSync, existsSync } from "fs";

// ── Parámetros ───────────────────────────────────────────────────────────────
const prompt     = process.argv[2];
const deviceType = process.argv[3] || "DESKTOP"; // DESKTOP | MOBILE | TABLET | AGNOSTIC

if (!prompt) {
  console.log(`
╔══════════════════════════════════════════════════════════════╗
║  Google Stitch - Generador de UI/UX para Sistema NG         ║
╠══════════════════════════════════════════════════════════════╣
║                                                              ║
║  Uso:                                                        ║
║    node generate-screen.js "<descripcion>" [dispositivo]     ║
║                                                              ║
║  Dispositivos: DESKTOP | MOBILE | TABLET | AGNOSTIC          ║
║                                                              ║
║  Ejemplos:                                                   ║
║    node generate-screen.js "Dashboard de inventario"         ║
║    node generate-screen.js "Login NAGSA naranja" MOBILE      ║
║    node generate-screen.js "Tabla de activos con filtros"    ║
║                                                              ║
╚══════════════════════════════════════════════════════════════╝
`);
  process.exit(1);
}

// ── Directorio de salida ─────────────────────────────────────────────────────
const outputDir = "./output";
if (!existsSync(outputDir)) mkdirSync(outputDir, { recursive: true });

// ── Generar ──────────────────────────────────────────────────────────────────
async function main() {
  console.log(`\n🎨 Generando pantalla UI...`);
  console.log(`   Prompt: "${prompt}"`);
  console.log(`   Dispositivo: ${deviceType}\n`);

  try {
    // Crear proyecto
    const result = await stitch.callTool("create_project", {
      title: `Sistema NG - ${new Date().toISOString().slice(0, 10)}`,
    });

    const project = stitch.project(result.projectId);

    // Generar pantalla
    const screen = await project.generate(prompt, deviceType);

    // Obtener HTML y screenshot
    const htmlUrl  = await screen.getHtml();
    const imageUrl = await screen.getImage();

    const timestamp = Date.now();
    const safeName  = prompt.slice(0, 30).replace(/[^a-zA-Z0-9]/g, "_");

    console.log(`✅ Pantalla generada exitosamente!\n`);
    console.log(`   📄 HTML:       ${htmlUrl}`);
    console.log(`   🖼️  Screenshot: ${imageUrl}`);
    console.log(`   📁 Proyecto:   ${result.projectId}\n`);

    // Guardar metadata local
    const meta = {
      prompt,
      deviceType,
      projectId: result.projectId,
      htmlUrl,
      imageUrl,
      generatedAt: new Date().toISOString(),
    };
    const metaPath = `${outputDir}/${safeName}_${timestamp}.json`;
    writeFileSync(metaPath, JSON.stringify(meta, null, 2));
    console.log(`   💾 Metadata:   ${metaPath}\n`);

  } catch (error) {
    console.error(`\n❌ Error: ${error.message}`);
    if (error.message.includes("API")) {
      console.error("   Verifica tu STITCH_API_KEY en el archivo .env");
    }
    process.exit(1);
  }
}

main();
