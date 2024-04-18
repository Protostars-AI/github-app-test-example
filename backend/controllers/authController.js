const speakeasy = require('speakeasy');

// Dummy database to store secret keys (for demonstration purposes)
const secretKeys = new Map();

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
    const { email, password } = req.body;

    // Check if email and password are provided
    if (!email || !password) {
      return res.status(400).json({ error: 'Email and password are required' });
    }
  
    // Here you would typically check the provided email and password against your database
    // For demonstration purposes, I'm assuming authentication is successful
    const userId = '123'; // This would be the user's ID fetched from the database
  
    // Generate a secret key for 2FA (for demonstration purposes)
    const secret = speakeasy.generateSecret({ length: 20 });
    secretKeys.set(userId, secret.base32);
  
    // Send response with user ID and secret key
    res.json({ userId, secret: secret.ascii });
};

exports.verify2FA = (req, res) => {
    const { userId, token } = req.body;
  
    // Retrieve secret key for the user from the dummy database
    const secret = secretKeys.get(userId);
  
    // Verify the token
    const verified = speakeasy.totp.verify({
      secret,
      encoding: 'base32',
      token,
      window: 2, // Allow tokens to be valid for 2 time steps (30 seconds each)
    });
  
    if (verified) {
      res.json({ success: true, message: '2FA verification successful' });
    } else {
      res.status(401).json({ success: false, error: 'Invalid token' });
    }
  };