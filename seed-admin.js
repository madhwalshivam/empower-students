const { createClient } = require('@supabase/supabase-js');
const fs = require('fs');
const path = require('path');

// Read .env.local manually
const envPath = path.join(__dirname, '.env.local');
const envContent = fs.readFileSync(envPath, 'utf8');

const env = {};
envContent.split('\n').forEach(line => {
  const match = line.match(/^\s*([\w.-]+)\s*=\s*(.*)?\s*$/);
  if (match) {
    const key = match[1];
    let value = match[2] || '';
    if (value.startsWith('"') && value.endsWith('"')) value = value.slice(1, -1);
    if (value.startsWith("'") && value.endsWith("'")) value = value.slice(1, -1);
    env[key] = value.trim();
  }
});

const supabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL;
const serviceRoleKey = env.SUPABASE_SERVICE_ROLE_KEY;

if (!supabaseUrl || !serviceRoleKey) {
  console.error("Missing Supabase credentials in .env.local!");
  process.exit(1);
}

const supabase = createClient(supabaseUrl, serviceRoleKey, {
  auth: {
    autoRefreshToken: false,
    persistSession: false
  }
});

async function run() {
  console.log("Checking for admin user...");
  const { data, error } = await supabase.auth.admin.listUsers();
  if (error) {
    console.error("Error listing users:", error);
    process.exit(1);
  }

  const users = data.users || [];
  const admin = users.find(u => u.email === 'admin@empowerstudents.in');
  if (admin) {
    console.log("Admin user already exists in auth.users.");
    
    if (admin.user_metadata?.role !== 'admin') {
      console.log("Updating admin user metadata...");
      const { error: updateErr } = await supabase.auth.admin.updateUserById(admin.id, {
        user_metadata: { ...admin.user_metadata, role: 'admin', name: 'System Administrator' }
      });
      if (updateErr) console.error("Error updating admin metadata:", updateErr);
      else console.log("Admin metadata updated successfully.");
    }
  } else {
    console.log("Creating admin user...");
    const { data: newUser, error: createErr } = await supabase.auth.admin.createUser({
      email: 'admin@empowerstudents.in',
      password: 'empower@2026',
      email_confirm: true,
      user_metadata: { role: 'admin', name: 'System Administrator' }
    });

    if (createErr) {
      console.error("Error creating admin user:", createErr);
    } else {
      console.log("Admin user created successfully:", newUser.user?.id);
    }
  }
}

run();
