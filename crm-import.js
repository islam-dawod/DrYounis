/* CRM import — adds an "ייבוא מקובץ" button, parses xlsx/csv in the browser
   (SheetJS handles UTF-16/CP1255 automatically), maps columns, and POSTs the
   mapped rows to /crm/index.php (action=import). Self-contained. */
(function () {
  var dataEl = document.getElementById('crmdata');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var CSRF = D.csrf, ST = D.statuses;
  var SHEET_URL = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };

  function post(d) { d.csrf = CSRF; return fetch('/crm/index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(d) }).then(function (r) { return r.json(); }); }
  function postJSON(obj) { obj.csrf = CSRF; return fetch('/crm/index.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(obj) }).then(function (r) { return r.json(); }); }

  var sheetReady = null;
  function loadSheet() {
    if (sheetReady) return sheetReady;
    sheetReady = new Promise(function (res, rej) {
      if (window.XLSX) return res();
      var s = document.createElement('script'); s.src = SHEET_URL;
      s.onload = function () { res(); }; s.onerror = function () { rej(new Error('load')); };
      document.head.appendChild(s);
    });
    return sheetReady;
  }

  var SYN = {
    name: ['שם', 'שם מלא', 'name', 'full name', 'fullname', 'lead', 'الاسم', 'اسم'],
    phone: ['טלפון', 'נייד', 'פלאפון', 'מספר', 'phone', 'mobile', 'tel', 'هاتف', 'جوال', 'رقم'],
    email: ['דוא"ל', 'דוא״ל', 'מייל', 'אימייל', 'email', 'mail', 'بريد', 'ايميل'],
    interest: ['טיפול', 'שירות', 'עניין', 'קמפיין', 'טופס', 'interest', 'service', 'treatment', 'form', 'علاج'],
    city: ['עיר', 'יישוב', 'ישוב', 'city', 'town', 'مدينة'],
    fit: ['מתאים', 'מה מתאים', 'fit', 'current'],
    question: ['שאלה', 'מידע', 'question', 'سؤال'],
    msg: ['הודעה', 'תוכן', 'message', 'msg', 'رسالة'],
    note: ['הערה', 'הערות', 'note', 'notes', 'comment', 'ملاحظة'],
    source: ['מקור', 'source', 'ערוץ', 'channel', 'مصدر'],
    ts: ['תאריך', 'זמן', 'date', 'time', 'created', 'تاريخ'],
    status: ['סטטוס', 'status', 'מצב', 'حالة'],
  };
  var FIELDS = { name: 'שם', phone: 'טלפון', email: 'דוא״ל', interest: 'טיפול / קמפיין', city: 'עיר', fit: 'מה מתאים כרגע?', question: 'שאלה / מידע', msg: 'הודעה', note: 'הערה (ליומן)', source: 'מקור', ts: 'תאריך', status: 'סטטוס' };

  function guess(header) {
    var map = {}; Object.keys(SYN).forEach(function (f) { map[f] = -1; });
    header.forEach(function (cell, i) {
      var c = String(cell == null ? '' : cell).toLowerCase().trim(); if (!c) return;
      Object.keys(SYN).forEach(function (f) {
        if (map[f] !== -1) return;
        for (var j = 0; j < SYN[f].length; j++) { if (c === SYN[f][j] || c.indexOf(SYN[f][j]) > -1) { map[f] = i; break; } }
      });
    });
    return map;
  }

  var modal, rows = [], header = [];
  function ensureModal() {
    if (modal) return;
    modal = document.createElement('div'); modal.className = 'modal'; modal.hidden = true;
    modal.innerHTML = '<div class="mbox" style="width:min(900px,100%)"><h3>ייבוא לידים מקובץ</h3><div id="impBody"></div></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) modal.hidden = true; });
  }
  function body() { return modal.querySelector('#impBody'); }

  function step1() {
    ensureModal(); modal.hidden = false;
    body().innerHTML = '<p style="color:#6a7a7a;font-size:.9rem;line-height:1.7">קבצים נתמכים: <b>xlsx</b> או <b>csv</b>. עד 5000 שורות. שורה ראשונה רצוי שתהיה כותרות.</p>' +
      '<input type="file" id="impFile" accept=".xlsx,.xls,.csv,.txt" style="display:block;width:100%;padding:24px;border:2px dashed #e3edec;border-radius:12px;background:#fbfdfd;cursor:pointer">' +
      '<div class="mact" style="margin-top:14px"><button type="button" class="cancel" id="impCancel">סגירה</button><span class="mmsg" id="impMsg"></span></div>';
    modal.querySelector('#impCancel').addEventListener('click', function () { modal.hidden = true; });
    modal.querySelector('#impFile').addEventListener('change', function (e) {
      var file = e.target.files[0]; if (!file) return;
      var msg = modal.querySelector('#impMsg'); msg.textContent = 'קורא…';
      loadSheet().then(function () {
        var rd = new FileReader();
        rd.onload = function () {
          try {
            var wb = XLSX.read(new Uint8Array(rd.result), { type: 'array' });
            var ws = wb.Sheets[wb.SheetNames[0]];
            var arr = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' });
            arr = arr.filter(function (r) { return r.join('').trim() !== ''; });
            if (arr.length < 1) { msg.textContent = 'הקובץ ריק'; return; }
            rows = arr; header = arr[0]; step2(file.name);
          } catch (err) { msg.textContent = 'שגיאה בקריאת הקובץ'; }
        };
        rd.readAsArrayBuffer(file);
      }).catch(function () { msg.textContent = 'טעינת המנתח נכשלה — בדקו חיבור'; });
    });
  }

  function step2(fname) {
    var map = guess(header);
    var opts = header.map(function (c, i) { return '<option value="' + i + '">' + esc(String(c) !== '' ? c : ('עמודה ' + (i + 1))) + '</option>'; }).join('');
    var mapRows = Object.keys(FIELDS).map(function (f) {
      var sel = header.map(function (c, i) { return '<option value="' + i + '"' + (map[f] === i ? ' selected' : '') + '>' + esc(String(c) !== '' ? c : ('עמודה ' + (i + 1))) + '</option>'; }).join('');
      return '<div style="display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid #eef4f3;flex-wrap:wrap">' +
        '<b style="min-width:150px;font-weight:600;font-size:.9rem">' + esc(FIELDS[f]) + (f === 'name' || f === 'phone' ? ' *' : '') + '</b>' +
        '<select data-f="' + f + '"><option value="-1">— ללא —</option>' + sel + '</select></div>';
    }).join('');
    var prev = rows.slice(0, 6).map(function (r, ri) {
      return '<tr>' + header.map(function (_, ci) { return (ri === 0 ? '<th>' : '<td>') + esc(r[ci] == null ? '' : r[ci]) + (ri === 0 ? '</th>' : '</td>'); }).join('') + '</tr>';
    }).join('');
    var stOpts = Object.keys(ST).map(function (k) { return '<option value="' + k + '">' + esc(ST[k]) + '</option>'; }).join('');
    body().innerHTML =
      '<p style="color:#6a7a7a;font-size:.9rem">נמצאו <b>' + (rows.length - 1) + '</b> שורות נתונים בקובץ <b>' + esc(fname) + '</b>. בדקו את מיפוי העמודות.</p>' +
      '<div style="overflow:auto;border:1px solid #e3edec;border-radius:12px;margin-bottom:14px;max-height:200px"><table style="width:100%;border-collapse:collapse;font-size:.82rem">' + prev + '</table></div>' +
      '<div id="impMap">' + mapRows + '</div>' +
      '<div style="display:flex;gap:18px;flex-wrap:wrap;margin:14px 0;font-size:.9rem;align-items:center">' +
      '<label style="display:flex;gap:7px;align-items:center"><input type="checkbox" id="impHdr" checked> השורה הראשונה כותרות</label>' +
      '<label style="display:flex;gap:7px;align-items:center"><input type="checkbox" id="impSkip" checked> דילוג על טלפון קיים</label>' +
      '<label>מקור: <input type="text" id="impSrc" value="ייבוא מקובץ" style="padding:7px 10px;border:1px solid #e3edec;border-radius:8px"></label>' +
      '<label>סטטוס: <select id="impStatus">' + stOpts + '</select></label></div>' +
      '<div class="mact"><button type="button" class="save" id="impGo">ייבוא ל־CRM ←</button><button type="button" class="cancel" id="impBack">ביטול</button><span class="mmsg" id="impMsg2"></span></div>';
    modal.querySelector('#impBack').addEventListener('click', function () { modal.hidden = true; });
    modal.querySelector('#impGo').addEventListener('click', doImport);
  }

  function doImport() {
    var map = {}; modal.querySelectorAll('#impMap select').forEach(function (s) { map[s.getAttribute('data-f')] = parseInt(s.value, 10); });
    var hasHeader = modal.querySelector('#impHdr').checked;
    var skipDup = modal.querySelector('#impSkip').checked;
    var src = modal.querySelector('#impSrc').value.trim() || 'ייבוא מקובץ';
    var st = modal.querySelector('#impStatus').value;
    var msg = modal.querySelector('#impMsg2'), go = modal.querySelector('#impGo');
    var get = function (r, f) { var i = map[f]; return (i >= 0 && r[i] != null) ? String(r[i]).trim() : ''; };
    var out = [];
    rows.forEach(function (r, idx) {
      if (idx === 0 && hasHeader) return;
      out.push({ name: get(r, 'name'), phone: get(r, 'phone'), email: get(r, 'email'), interest: get(r, 'interest'),
        city: get(r, 'city'), fit: get(r, 'fit'), question: get(r, 'question'), msg: get(r, 'msg'),
        note: get(r, 'note'), source: get(r, 'source'), ts: get(r, 'ts'), status: get(r, 'status') });
    });
    if (!out.length) { msg.textContent = 'אין שורות לייבוא'; return; }
    go.disabled = true; msg.textContent = 'מייבא ' + out.length + ' שורות…';
    var added = 0, dup = 0, bad = 0, chunks = [], i;
    for (i = 0; i < out.length; i += 400) chunks.push(out.slice(i, i + 400));
    var ci = 0;
    (function next() {
      if (ci >= chunks.length) {
        msg.textContent = '';
        body().innerHTML = '<h3 style="color:#0B6F70;margin:0 0 10px">הייבוא הושלם</h3>' +
          '<p style="font-size:1rem">נוספו <b>' + added + '</b> · דילוג (טלפון קיים) <b>' + dup + '</b> · שורות לא תקינות <b>' + bad + '</b></p>' +
          '<div class="mact" style="margin-top:14px">' +
          (added ? '<button type="button" class="cancel" id="impUndo" style="border-color:#e6b7b1;color:#c0392b">↺ ביטול הייבוא</button>' : '') +
          '<button type="button" class="save" id="impDone">סיום ורענון</button></div>';
        modal.querySelector('#impDone').addEventListener('click', function () { location.reload(); });
        var u = modal.querySelector('#impUndo');
        if (u) u.addEventListener('click', function () { if (!confirm('למחוק את כל הלידים שיובאו עכשיו?')) return; u.disabled = true; post({ action: 'import_undo' }).then(function (r) { alert(r && r.ok ? ('בוטלו ' + r.undone + ' לידים') : 'שגיאה'); location.reload(); }); });
        return;
      }
      postJSON({ action: 'import', rows: chunks[ci], skip_dup: skipDup ? 1 : 0, default_source: src, default_status: st }).then(function (r) {
        if (r && r.ok) { added += r.added || 0; dup += r.dup || 0; bad += r.bad || 0; }
        ci++; msg.textContent = 'מייבא… (' + Math.min(ci * 400, out.length) + '/' + out.length + ')'; next();
      }).catch(function () { go.disabled = false; msg.textContent = 'שגיאה בייבוא'; });
    })();
  }

  // add the toolbar button once the dashboard has rendered
  function addButton() {
    var tb = document.querySelector('.toolbar'); if (!tb) { return setTimeout(addButton, 150); }
    if (document.getElementById('impBtn')) return;
    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn'; b.id = 'impBtn';
    b.style.background = '#fff'; b.style.color = 'var(--teal)'; b.style.border = '1px solid var(--teal)';
    b.textContent = '⬆ ייבוא מקובץ';
    var exp = tb.querySelector('a.btn'); if (exp) tb.insertBefore(b, exp.nextSibling); else tb.appendChild(b);
    b.addEventListener('click', step1);
  }
  addButton();
})();
