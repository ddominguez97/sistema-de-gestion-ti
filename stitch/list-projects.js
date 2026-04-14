/**
 * list-projects.js — Listar proyectos existentes en Stitch
 *
 * Uso:
 *   node list-projects.js
 */

import "dotenv/config";
import { stitch } from "@google/stitch-sdk";

async function main() {
  console.log("\n📋 Proyectos en Google Stitch:\n");

  try {
    const projects = await stitch.projects();

    if (projects.length === 0) {
      console.log("   (No hay proyectos aún. Usa generate-screen.js para crear uno)\n");
      return;
    }

    for (const project of projects) {
      console.log(`   📁 ${project.title || project.id}`);
      console.log(`      ID: ${project.id}`);

      const screens = await project.screens();
      console.log(`      Pantallas: ${screens.length}\n`);
    }

  } catch (error) {
    console.error(`\n❌ Error: ${error.message}`);
    process.exit(1);
  }
}

main();
