/**
 * generate-variants.js — Generar variantes de diseño con Stitch AI
 *
 * Uso:
 *   node generate-variants.js "Dashboard moderno para NAGSA" 3 EXPLORE
 *
 * Parámetros:
 *   1. Prompt de diseño (requerido)
 *   2. Cantidad de variantes: 1-5 (default: 3)
 *   3. Rango creativo: REFINE | EXPLORE | REIMAGINE (default: EXPLORE)
 */

import "dotenv/config";
import { stitch } from "@google/stitch-sdk";

const prompt        = process.argv[2];
const variantCount  = parseInt(process.argv[3]) || 3;
const creativeRange = process.argv[4] || "EXPLORE";

if (!prompt) {
  console.log(`
  Uso: node generate-variants.js "<prompt>" [cantidad] [rango]

  Rangos creativos:
    REFINE    → Variaciones sutiles del diseño original
    EXPLORE   → Exploraciones moderadas (default)
    REIMAGINE → Rediseños creativos completos
  `);
  process.exit(1);
}

async function main() {
  console.log(`\n🎨 Generando ${variantCount} variantes (${creativeRange})...`);
  console.log(`   Prompt: "${prompt}"\n`);

  try {
    const result = await stitch.callTool("create_project", {
      title: `Sistema NG Variants - ${new Date().toISOString().slice(0, 10)}`,
    });

    const project = stitch.project(result.projectId);

    // Generar pantalla base
    console.log("   1/2 Generando diseño base...");
    const screen = await project.generate(prompt, "DESKTOP");

    // Generar variantes
    console.log("   2/2 Generando variantes...\n");
    const variants = await screen.variants(
      `Crear variantes para Sistema NG con tema corporativo naranja #e05816`,
      {
        variantCount,
        creativeRange,
        aspects: ["LAYOUT", "COLOR_SCHEME", "TEXT_FONT"],
      }
    );

    console.log(`✅ ${variants.length} variantes generadas!\n`);

    for (let i = 0; i < variants.length; i++) {
      const html  = await variants[i].getHtml();
      const image = await variants[i].getImage();
      console.log(`   Variante ${i + 1}:`);
      console.log(`     HTML:  ${html}`);
      console.log(`     Image: ${image}\n`);
    }

  } catch (error) {
    console.error(`\n❌ Error: ${error.message}`);
    process.exit(1);
  }
}

main();
