const express = require('express');
const session = require('express-session');
const path = require('path');
const brandingMiddleware = require('./middleware/branding');

const app = express();
const PORT = process.env.PORT || 8080;

// View engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Body parsers - UTF-8 explicito
app.use(express.json({ type: 'application/json', charset: 'utf-8' }));
app.use(express.urlencoded({ extended: true }));

// Forzar charset UTF-8 en todas las respuestas JSON
app.use((req, res, next) => {
  const originalJson = res.json.bind(res);
  res.json = (data) => {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    return originalJson(data);
  };
  next();
});

// Session - generar secret automaticamente si no existe
const { loadConfig: loadCfg, saveConfig: saveCfg } = require('./config/config');
const sessionSecret = (() => {
  try {
    const cfg = loadCfg();
    if (!cfg.session_secret) {
      const crypto = require('crypto');
      cfg.session_secret = crypto.randomBytes(32).toString('hex');
      saveCfg(cfg);
      console.log('Session secret generado automaticamente.');
    }
    return cfg.session_secret;
  } catch { return require('crypto').randomBytes(32).toString('hex'); }
})();
app.use(session({
  secret: sessionSecret,
  resave: false,
  saveUninitialized: false,
  cookie: { maxAge: 4 * 60 * 60 * 1000 }, // 4 horas
}));

// Static files
app.use('/public', express.static(path.join(__dirname, 'public')));
// Serve logo and static assets from root project too
app.use('/public', express.static(path.join(__dirname, '..')));

// Branding middleware - inject config/branding into all views
app.use(brandingMiddleware);

// Pass session to all views
app.use((req, res, next) => {
  res.locals.session = req.session;
  next();
});

// Proximamente route
app.get('/proximamente', (req, res) => {
  if (!req.session.nagsa_user) return res.redirect('/');
  const nombres = { inversiones: 'Formato de Inversion', permisos: 'Permisos y Notificaciones' };
  const m = req.query.m || '';
  res.render('proximamente', { titulo: nombres[m] || 'Nuevo Modulo' });
});

// Routes
app.use('/', require('./routes/index'));
app.use('/admin', require('./routes/admin'));
app.use('/etiquetas', require('./routes/etiquetas'));
app.use('/etiquetas/print', require('./routes/etiquetas-print'));
app.use('/actas', require('./routes/actas'));
app.use('/reportes', require('./routes/reportes'));

app.listen(PORT, () => {
  console.log(`Sistema NG corriendo en http://localhost:${PORT}`);
});
