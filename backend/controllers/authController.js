exports.signup = (req, res) => {
    const { name, email, password } = req.body;

    // Check if all fields are provided
    if (!name || !email || !password) {
        return res.status(400).json({ error: 'All fields are required' });
    }

    // Check if password meets minimum length requirement
    if (password.length < 8) {
        return res.status(400).json({ error: 'Password must be at least 8 characters long' });
    }

    // Here you can implement the logic to store the user in your database
    // For demonstration purposes, I'm just sending a success message
    res.send('Signup successful!');
  };
  
  exports.signin = (req, res) => {
    // Implement signin logic here
    res.send('Signin successful!');
  };