const { createClient } = require('@supabase/supabase-js');

const supabaseUrl = 'https://nkaqonnblhxfuhmzgnpu.supabase.co';
const supabaseServiceKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5rYXFvbm5ibGh4ZnVobXpnbnB1Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4MDEzMjk0NiwiZXhwIjoyMDk1NzA4OTQ2fQ.Cump2sJ3Nw9VTlqMVLIehh5SRMhaVMTX0FpswdbqBsk';
const supabaseAnonKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5rYXFvbm5ibGh4ZnVobXpnbnB1Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODAxMzI5NDYsImV4cCI6MjA5NTcwODk0Nn0.nvByCLDdI_mNSX9WJyykHRr87VH7nHNouASqR-SdbvQ';

async function run() {
  const adminClient = createClient(supabaseUrl, supabaseServiceKey);
  const anonClient = createClient(supabaseUrl, supabaseAnonKey);

  console.log("1. Checking if test user exists...");
  const email = 'test_parent_model@example.com';
  const { data: userList } = await adminClient.auth.admin.listUsers();
  const existing = userList.users.find(u => u.email === email);

  let userId;
  if (existing) {
    console.log("User exists:", existing.id);
    userId = existing.id;
  } else {
    console.log("Creating new user...");
    const { data, error } = await adminClient.auth.admin.createUser({
      email,
      password: 'password123',
      email_confirm: true,
      user_metadata: { name: 'Model Parent Test', phone: '9999999999', role: 'parent' }
    });
    if (error) {
      console.error("Create error:", error);
      return;
    }
    console.log("User created:", data.user.id);
    userId = data.user.id;
  }

  console.log("2. Attempting to log in using anonClient to check cookie/token generation...");
  const { data: sessionData, error: loginErr } = await anonClient.auth.signInWithPassword({
    email,
    password: 'password123'
  });

  if (loginErr) {
    console.error("Login error:", loginErr);
    return;
  }
  console.log("Login successful! Session details:", {
    access_token_exists: !!sessionData.session?.access_token,
    user_id: sessionData.user.id
  });

  console.log("3. Attempting to query/provision parent record for this user using admin client (simulating dashboard logic)...");
  const { data: parentRecord, error: parentGetErr } = await adminClient
    .from('parents')
    .select('*')
    .eq('id', userId)
    .maybeSingle();

  if (parentGetErr) {
    console.error("Get parent error:", parentGetErr);
  } else {
    console.log("Parent record query result:", parentRecord);
  }

  if (!parentRecord) {
    console.log("Provisioning parent...");
    const { error: insertErr } = await adminClient.from('parents').insert({
      id: userId,
      whatsapp: '+919999999999',
      name: 'Model Parent Test',
      credits: 100
    });
    if (insertErr) {
      console.error("Insert parent error:", insertErr);
    } else {
      console.log("Parent successfully provisioned using admin client!");
    }
  }

  console.log("4. Fetching children for the parent...");
  const { data: children, error: childrenErr } = await adminClient
    .from('children')
    .select('*')
    .eq('parent_id', userId);

  if (childrenErr) {
    console.error("Children fetch error:", childrenErr);
  } else {
    console.log("Children list:", children);
  }
}

run();
